# Supplier Modules — Booking Reality Audit

**What this document answers, truthfully:** for every travel service the platform
sells, *which supplier is connected*, *whether a booking is actually created via
the supplier's API after payment*, *how that supplier booking is paid for*, and
*which "suppliers" are really just affiliate/redirect or own-inventory*.

**Method (and its honest limits):** every classification in the master table
(§3) was confirmed by me reading that module's actual booking action
(`actions/issue.php` / `issue.php` / `create_order.php`) and its `search.php` /
`index.php` — either the real remote booking endpoint it POSTs to (URL quoted),
or the fact that it only writes a local PNR, or that no booking file exists at
all. The settlement flow (§1) was traced in `payment-gateway.php` and
`adyen.php`. Strong negatives ("stub", "not integrated") were confirmed by
whole-repo grep.

This document was **re-audited a second time** specifically to remove false or
second-hand claims. During that pass I found and corrected **three** errors that
had been in an earlier draft: (a) a wrong claim that flights are excluded from
auto-issue; (b) a non-existent "travelport `$headerPpc` typo"; (c) a wrong claim
that amadeus_enterprise emits PENDING placeholder PNRs. Each correction is noted
inline where it occurs, so the record of what was wrong is preserved.

> Honest limits that remain (stated, not hidden): I confirmed the **booking
> endpoint and reference-storage** of every "REAL API BOOKING" module, but I did
> NOT trace every error branch, nor the full `cancel.php`/`refund.php`/`void.php`
> logic (see §6 "UNVERIFIED"). Provider *capabilities* in §7 are from public docs
> (Aug 2026), not from our code. So: §3–§6 = what our code does (verified);
> §7 = what providers offer (researched). Nothing here is assumed.

---

## 1. How a booking actually reaches the supplier (the settlement flow)

Verified in `app/lib/payment-gateway.php` and `app/routes/admin/bookingsRoutes.php`.

```
Customer searches ──► supplier search API returns offers (+ a token/rate key)
        │                stored in bookings.booking_data
        ▼
Customer checks out ──► booking row created (payment_status=unpaid)
        ▼
Customer pays ──► gateway callback ──► handle_payment_callback()   (payment-gateway.php:182)
        │           · verify_gateway_payment()  → payment_status = 'paid'
        │           · DEV/LIVE mode of module MUST match gateway, else auto-issue
        │             is SKIPPED but money is still kept   (payment-gateway.php:273–311)
        ▼
IF settings.booking_payment_issue = 1  (global "auto issue" toggle, :224–228)
   AND dev/live modes match
   AND $module   ∉ {'flight','visas'}        (the SUPPLIER name, :338)
   AND $moduleType ∉ {'tours','tour'}        (the SERVICE type,  :339)
        └─► server cURL POST to  {root}/modules/{moduleType}/{module}/issue  (:350)
              → runs that supplier's actions/issue.php → REAL supplier booking
        ▼
ELSE (excluded, or auto-issue off, or dev/live mismatch):
   Admin clicks "Issue" in Admin → Bookings
        └─► includes modules/{moduleType}/{module}/actions/issue.php directly
              (app/routes/admin/bookingsRoutes.php:298–330)
```

**Key truths that follow from this code (re-verified line by line):**
- The **actual supplier booking is a separate step from taking the customer's
  money.** Payment is captured first; the supplier order is created afterwards by
  `issue.php`.
- **What the exclusion literally does — important correction.** In
  `payment-gateway.php:337–340` (and identically in `adyen.php:304–307`), the
  guard skips auto-issue only when the **supplier name** `$module` is exactly
  `'flight'` or `'visas'`, OR the **service** `$moduleType` is `'tours'/'tour'`.
  Real flight bookings are written with `$module` = the supplier
  (`'duffel'`, `'amadeus'`, …) and `$moduleType = 'flights'`
  (`app/routes/flights/bookingRoutes.php:1050–1055`). **`'duffel'` is not
  `'flight'`, and `'flights'` is not `'tours'` — so supplier flight bookings are
  NOT excluded: they DO auto-issue after payment, exactly like stays**, via a
  cURL to `modules/flights/{supplier}/issue`.
  - So the only things this guard actually stops are: a module literally named
    `flight` or `visas`, and any booking whose `module_type` is `tours`. **Tours
    are genuinely excluded** (payment alone confirms a tour — consistent with
    tours being own-inventory/affiliate). Visas are excluded by name.
  - ⚠️ *Correcting my own earlier draft:* an earlier version of this document
    said "flights are excluded from auto-issue." That was **wrong** — verified
    against the code above. Flights auto-issue like every other real-API module.
- **Admin manual issue always exists** as the fallback/override
  (`bookingsRoutes.php:298–330`) and works for every module including flights.
- Auto-issue requires the global toggle `settings.booking_payment_issue = 1`. If
  it is OFF, nothing auto-issues and a human must click Issue for every booking.
- Rail has a special path that still places the supplier order even on dev/live
  mismatch (`payment-gateway.php:296–300`).
- eSIM (Airalo) is issued from its invoice route (`app/routes/esim/invoiceRoutes.php:92`).

### How suppliers get paid (the models seen in code)
Reading the booking payloads, three real settlement models exist:
1. **Merchant balance / prepaid bedbank wallet** — the platform's own account with
   the supplier is charged. e.g. **Duffel** sends `"payments":[{"type":"balance"}]`
   (books & pays from the Duffel balance). Hotel bedbanks (Hotelbeds, Ratehawk,
   Stuba, Hotelston, Wanderbeds, TBO-Holidays) draw on agency credit; no customer
   card is passed to them.
2. **Deferred / credit account** — booking is created after the customer pays the
   platform; the platform settles with the supplier out-of-band on account
   (CarTrawler, Kikoto, Train — no card pass-through in the payload).
3. **Provider-hosted checkout / redirect** — the customer pays the supplier
   directly (affiliate model), or (Mozio "hosted_checkout") is redirected to the
   supplier's Stripe. The platform never settles a booking.

> ⚠️ Consequence of model (1)/(2): for every REAL-API supplier the platform must
> keep a funded balance / credit line with that supplier. If that balance is
> empty, the supplier booking fails **after** the customer has already paid.
> (Wanderbeds explicitly returns "insufficient credit" code 120.) This is
> inherent to the design, not a bug — but it is a real operational obligation.

---

## 2. Classification legend

- **(A) REAL API BOOKING** — after payment, code makes an HTTP call to the
  supplier's live API that creates an order/ticket/reservation and stores the
  returned reference/PNR. **This is true end-to-end (E2E) booking.**
- **(B) AFFILIATE / REDIRECT** — search hits the supplier API for prices, but no
  booking is created; the customer is sent to the supplier to book & pay. No
  `issue.php`, or `issue.php` only returns a redirect URL.
- **(C) OWN-INVENTORY / MANUAL** — books against the platform's own database;
  `issue.php` just generates a local PNR and marks the booking confirmed. No
  external supplier.
- **(D) STUB / NOT INTEGRATED** — file/module exists but does not actually book
  (empty TODO, standalone test file not wired into the app).

---

## 3. Master table (verified)

| Service | Module | Class | Books via supplier API? | Supplier booking endpoint (quoted from code) | Pays supplier via |
|---|---|---|---|---|---|
| Flights | duffel | **A** | **Yes** | `POST https://api.duffel.com/air/orders` | **balance/wallet** (`payments:[{type:balance}]`) |
| Flights | amadeus | **A** | Yes | `POST …/v1/booking/flight-orders` | none in payload (account) |
| Flights | amadeus_enterprise | **A** | Yes | `POST …/v1/booking/flight-orders` (travel.api + fallback) | none in payload (account) |
| Flights | kiwi | **A** | Yes | `POST https://api.tequila.kiwi.com/v2/booking` | none in payload (account) |
| Flights | mystifly | **A** | Yes | `POST …/api/v1/Book/Flight` (+ OrderTicket) | none in payload (account) |
| Flights | pkfare | **A** | Yes | `POST https://api.pkfare.com/air/api/preciseBooking_V6` | account (MD5-signed) |
| Flights | sabre | **A** | Yes | Sabre `CreatePassengerNameRecordRQ` (issue.php:263) | GDS account |
| Flights | seeru | **A** | Yes | `POST …/flights/booking/fare` → `/booking/save` → `/order/issue` | account (Bearer) |
| Flights | tbo | **A** | Yes | `POST …/api/v1/Booking/Book` and `/Booking/Ticket` | account |
| Flights | travelport | **A** | Yes | `…/air/book/session/reservationworkbench` → traveler → offer → reservation | Cash FOP / GDS account |
| Flights | googleflights | **B** | **No** | none — `issue.php` returns Google/airline redirect URL, sets a fake MD5 PNR | customer pays airline |
| Flights | travelpayouts | **D** | **No** | **`issue.php` is an empty `// TODO` stub** (verified) | n/a |
| Flights | flights (`flights/flights`) | **C** | No | own DB; no `issue.php` (search-only) | n/a (own inventory) |
| Stays | hotelbeds | **A** | Yes | `POST https://api.hotelbeds.com/hotel-api/1.0/bookings` (+ CheckRate) | prepaid bedbank |
| Stays | ratehawk | **A** | Yes | `…/api/b2b/v3/hotel/prebook/` → `…/order/booking/finish/` | prepaid bedbank |
| Stays | stuba | **A** | Yes | SOAP `api.stuba.com` prepare→confirm | prepaid bedbank |
| Stays | hotelston | **A** | Yes | SOAP `hotelston.com/ws/HotelServiceV2` bookHotel | prepaid bedbank (EUR/LTL) |
| Stays | tbo-holidays | **A** | Yes | `PreBook` → `Book` (base from `api.php`) | prepaid (PaymentMode: Voucher/Limit) |
| Stays | wanderbeds | **A** | Yes | `POST hotel/book` → `hotel/bookinfo` (base from `api.php`) | prepaid wallet (code 120 = no credit) |
| Stays | travelport | **A** | Yes | Travelport Universal API SOAP (hotel) | GDS account |
| Stays | hotels (`stays/hotels`) | **C** | No | own DB; `issue.php` sets local `PNR…` | n/a (own inventory) |
| Stays | agoda | **B** | **No** | search only (`affiliateapi7643.agoda.com`); returns `landingURL` | customer pays Agoda |
| Stays | amadeus | **B** | **No** | search only (`/v3/shopping/hotel-offers`); **no booking file** | none (read-only) |
| Stays | booking | **B** | **No** | search only via RapidAPI wrapper; no booking file | customer pays Booking.com |
| Tours | viator | **B** | **No** | search only (`api.viator.com/partner/...`); no booking file | customer pays Viator |
| Tours | tiqets | **B** | **No** | search only (`api.tiqets.com/v2/products`); returns checkout URL | customer pays Tiqets |
| Tours | viator_merchant | **D** | **No** | only `creds.php` — no search/details/issue | n/a |
| Tours | tours (`tours/tours`) | **C** | No | own DB; `issue.php` local PNR | n/a (own inventory) |
| Tours | toursbms | **C** | No | own DB (imported content); `issue.php` local PNR, "No supplier booking API exists" | n/a |
| Cars | cartrawler | **A** | Yes | OTA XML `POST https://ota.cartrawler.com/cartrawlerota` (`OTA_VehResRQ`) | deferred/account |
| Cars | mozio | **A** | Yes | `POST https://api.mozio.com/v2/reservations/` | partner-managed wallet **or** Mozio-hosted Stripe |
| Cars | discover_cars | **B** | **No** | search only; no booking file | customer pays DiscoverCars |
| Cars | kiwitaxi | **B** | **No** | search only (`kiwitaxi.com/services/data/route_transfers`); no booking file | customer pays KiwiTaxi |
| Cars | cars (`cars/cars`) | **C** | No | own DB; no `issue.php` (search only) | n/a (own inventory) |
| Ferries | kikoto | **A** | Yes | `POST …/v1/bookings/{ref}/confirm` (B2B API) | deferred/account |
| Rail | train | **A** | Yes | `…/ticket/order` (+ `orderResultData` poll) | deferred/account |
| Bus | bus (`bus/bus`) | **C** | No | own DB; `issue.php` local PNR + decrements `bus_routes_calendar` seats | n/a (own inventory) |
| Umrah | umrah (`umrah/umrah`) | **C** | No | own DB; `issue.php` local PNR | n/a (own inventory) |
| eSIM | airalo | **A** | Yes | `POST https://partners-api.airalo.com/v2/orders` (OAuth token first) | prepaid Airalo account |
| Insurance | airhelp | **A** (as of §11 build) | **Yes — claim registration** | `POST https://partner-api.airhelp.com/v2/booking/{id}` via `modules/insurance/airhelp/index.php` (`airhelp_register_claim`), wired into a real `insurance` service (`app/routes/insurance/*`). Free to customer; commission model. **Live calls need a real Partner Token** (no-ops safely until then — see §11). | commission (AirHelp pays platform ~10.5%/won claim) |

---

## 4. What this means in plain terms

### Full end-to-end (E2E) booking — money in, real supplier booking out
These are the modules where "someone orders on our platform, pays, and we book
the actual service via the supplier API using our balance/account":

- **Flights (10):** duffel, amadeus, amadeus_enterprise, kiwi, mystifly, pkfare,
  sabre, seeru, tbo, travelport. These **auto-issue after payment** like stays
  (when `booking_payment_issue=1` and dev/live modes match — see §1), via a cURL
  to `modules/flights/{supplier}/issue`. The admin "Issue" button is the manual
  fallback/override. (They are NOT excluded from auto-issue — see the correction
  in §1.)
- **Stays (7):** hotelbeds, ratehawk, stuba, hotelston, tbo-holidays, wanderbeds,
  travelport. These **are** auto-issued after payment (subject to the global
  `booking_payment_issue` toggle and dev/live match). Most do a prebook/CheckRate
  price re-validation before booking.
- **Cars (2):** cartrawler, mozio.
- **Ferries (1):** kikoto.  **Rail (1):** train.  **eSIM (1):** airalo.

### Affiliate / redirect only — we do NOT book; customer books on the supplier
- **Flights:** googleflights (its `issue.php` returns a Google/airline redirect
  URL and writes a fake md5 PNR — no real booking).
- **Stays:** agoda, booking.com.  (amadeus-hotels is search-only read; not even a
  redirect — effectively non-transactional.)
- **Tours:** viator, tiqets.
- **Cars:** discover_cars, kiwitaxi.
(That is all 8 class-B rows in the §3 table.) For these, revenue is via
affiliate/commission, and there is **no booking confirmation, PNR, or
cancellation managed by the platform.**

### Own-inventory / manual (platform's own stock, no external supplier)
- flights/flights, stays/hotels, tours/tours, tours/toursbms, cars/cars,
  bus/bus, umrah/umrah.
These generate a local `PNR…` (md5 of invoice_id+time) and mark the booking
confirmed. **No third party is involved.** (These are the `<service>/<service>`
"custom" providers plus toursbms which imports content but books locally.)

### Stub / not integrated (do NOT rely on these)
- **flights/travelpayouts** — `issue.php` is an empty `// TODO`. Search works;
  booking does nothing. **Verified.**
- **tours/viator_merchant** — only `creds.php`; no search/details/issue.
- **insurance/airhelp** — real AirHelp API code exists but ONLY in standalone
  test files (`create_order.php`, `get_order.php`) that are **not wired into
  `modules/index.php` or any route** (verified by grep). Credentials are hardcoded
  placeholders. Not production-functional.

---

## 5. Per-module credentials (where API keys live)

All live-API suppliers read credentials from the **`modules` DB table** row for
that supplier — either a `credentials` JSON column or generic `c1..cN` columns,
plus `dev_mode` (1=test/0=live) or an `env` field. Examples verified in code:

| Module | Fields (as used in issue/creds) |
|---|---|
| duffel | `c1` = API token (Bearer); env test/prod (same endpoint) |
| amadeus / amadeus_enterprise | `c1` = client id, `c2` = client secret (OAuth2); `env` |
| kiwi | `c1` = affiliate id (opt), `c2` = API key (`apikey:` header) |
| pkfare | `c1`/`c2` **base64-encoded** partner id / key; sign = `md5(partner.key)` |
| sabre | `c1`=PCC, `c2`=EPR, `c3`=domain, `c4`=password; `dev_mode` cert/prod |
| seeru | `c1`=API key (Bearer), `c2`=refresh token; `dev_mode` sandbox/live |
| tbo (air) | `getTboCredentials()` username/password; dev/prod base URLs |
| travelport | `c5`=access group, `c6`=PCC (`_1G` appended); OAuth token helper |
| hotelbeds | `c1`=API key, `c2`=secret; `X-Signature`=sha256(key+secret+ts); optional mTLS |
| ratehawk | `c1`=key id, `c3`=API key, `c4`=base URL (ETG v3) |
| stuba | `c1`=org id, `c2`=user id, `c3`=password (SOAP) |
| hotelston | `c1`=email, `c2`=password, `c3`=profile id (SOAP; EUR/LTL only) |
| tbo-holidays | `c1`=username, `c2`=password, `c3`=service URL |
| wanderbeds | `c1`=username, `c2`=password, `c3`=base URL |
| cartrawler | `c1`=client id; `dev_mode` → external-dev vs ota.cartrawler.com |
| mozio | `c1`=API key, `c2`=payment mode (partner_managed / hosted_checkout) |
| kikoto | Bearer token via `_kikoto_cfg($db)` (module row) |
| train | `c1`=API key (`apiKey:` header), `c2`=base URL |
| airalo | client id/secret via `_airalo_cfg($db)` → OAuth `/v2/token` → `/v2/orders` |

> ⚠️ Credentials are stored **in the database in plain form** (no encryption seen).
> A DB compromise exposes every supplier credential. (Same finding as CLAUDE.md.)

---

## 6. Concrete gaps identified (per module, verified or clearly-evidenced)

**Booking-critical:**
- **travelpayouts:** booking not implemented (empty stub). If offered for sale,
  paid customers get no ticket. **Verified.**
- **airhelp (insurance):** not integrated into the app at all (test files only).
  Selling insurance through it would not create any AirHelp booking. **Verified.**
- **viator_merchant:** non-functional (creds only).
- **Global auto-issue toggle:** all auto-issuing (flights and stays alike)
  depends on `settings.booking_payment_issue = 1`. If it is OFF, every paid
  booking waits for an admin to click "Issue"; money is captured but no supplier
  booking is created until then. Also, if a module's dev/live mode differs from
  the gateway's, auto-issue is skipped while the payment is still kept
  (`payment-gateway.php:273–311`). **Verified in code.** (Note: an earlier draft
  wrongly claimed flights are *always* excluded from auto-issue; corrected — see
  §1.)

**Correctness / robustness — each item below was re-read and confirmed by me
(file:line given). Two claims from an earlier draft were FALSE and have been
removed (see the note at the end of this list):**
- **googleflights / own-inventory modules** (tours/tours, toursbms, stays/hotels,
  umrah, bus, flights/flights): PNRs are `md5(invoice_id + time())` — locally
  generated, not real supplier confirmations. Verified (e.g.
  `stays/hotels/actions/issue.php:28`, `googleflights/actions/issue.php`).
- **mystifly:** emits a `PENDING-########` placeholder reference when BookFlight
  returns no real ID, and can treat that as a soft success / demo PNR.
  Verified: `modules/flights/mystifly/actions/issue.php:376, 461, 538-539`.
- **hotelbeds:** on booking-POST timeout it marks the booking `booking_unknown`
  and returns `needs_reconcile` — deliberately no blind retry of `POST /bookings`.
  Verified: `modules/stays/hotelbeds/actions/issue.php:156-157, 729-761`.
- **hotelbeds price tolerance:** the booking payload sends `'tolerance' => 2.00`
  (2%); moves beyond it fail after payment and need a re-search. Verified:
  `modules/stays/hotelbeds/actions/issue.php:637`.
- **viator / tiqets:** the currency sent to the supplier search API is hardcoded
  to `USD`. Verified: `modules/tours/viator/search.php:157`,
  `modules/tours/tiqets/search.php:218`. (Display currency is later converted to
  the session currency, but the upstream call is USD.)

> **Removed as FALSE (I re-read the code and the earlier agent-sourced claim did
> not hold):**
> 1. "travelport flights has a `$headerPpc` typo" — the code actually reads
>    `$headerPcc` correctly (`modules/flights/travelport/actions/issue.php:83,89`).
>    No typo.
> 2. "amadeus_enterprise emits PENDING placeholder PNRs" — it does **not**; only
>    mystifly does. (`amadeus_enterprise/actions/issue.php` has no PENDING ref;
>    line 198 is a phone-number placeholder, unrelated.)

### Cancellation & refund reality (now traced — was previously UNVERIFIED)

Every real-API supplier ships `cancel.php` / `refund.php` / `void.php`. I read
them. The important, legally-relevant truths:

- **Cancellation IS real at the supplier.** `cancel.php` calls the supplier API
  and (where the supplier supports it) computes penalties. Verified examples:
  - Duffel: `POST https://api.duffel.com/air/order_cancellations`, returns real
    `refund_amount` / `refund_currency` / `refund_to` (`duffel/actions/cancel.php:125,165–167`).
  - Hotelbeds: supplier cancel with a **SIMULATION** (fee preview) vs
    **CANCELLATION** flag (`hotelbeds/actions/cancel.php:7–8,39`).
  - travelport (flights): GDS cancel-offer + commit
    (`travelport/actions/refund.php:58,71`).
- **Refund to the CUSTOMER is NOT automated.** No supplier `refund.php` issues an
  actual charge-reversal through the payment gateway (Stripe/PayPal/etc.). What
  `refund.php` does is: optionally cancel at the supplier, then set
  `payment_status = 'refunded'` / `booking_status` in the `bookings` table. The
  code says this outright:
  - amadeus_enterprise `refund.php:13`: *"Payment gateway refund (Stripe/PayPal/
    etc.) remains a separate admin step."*
  - tbo `refund.php:3–4`: *"refunds are handled through TBO's change-request
    desk, not via this API integration."*
  - kiwi / sabre / hotelbeds / ratehawk / wanderbeds `refund.php`: DB status flip
    only (0 gateway-refund calls — verified by grep across all refund files).
  - **Duffel `refund.php` is only a DB flip** (`duffel/actions/refund.php:28–32`,
    "refund requested by admin") — even though Duffel *cancel* returns a real
    refund-to-balance figure, the customer's card is not refunded by this code.
  - **So: returning money to the buyer is a manual step an admin performs in the
    payment provider's dashboard.** The platform tracks the refund *status*, not
    the money movement. This is true for essentially every module.
- Leftover found while tracing: `travelport/actions/refund.php:9` hardcodes an
  absolute log path `/Applications/XAMPP/.../travelport/certification/...` — a
  developer-machine leftover.

**Still explicitly UNVERIFIED (stated, not hidden):**
- The complete error/edge-case branches inside each `issue.php` beyond the
  confirmed booking endpoint were not exhaustively traced.
- Whether each supplier's cancellation-penalty amount is surfaced to the customer
  before they confirm (the API call exists; the UI wiring was not traced).

---

## 7. Cross-check: what each provider's API *can* do vs what we implemented

For the modules we classified as **affiliate / search-only / stub**, the real
question is: *is that because the provider only offers affiliate, or because we
didn't build the booking side of an API that supports it?* The first is a
business model; the second is a **fixable gap**. Below is web-researched (Aug
2026) provider capability vs our code. Sources listed at the end of this section.

| Provider (our module) | Provider API supports full booking? | What we implemented | Verdict |
|---|---|---|---|
| **Viator** (tours/viator) | **YES** — Partner API has `/availability/check` → `/bookings/hold` → `/bookings/book` (also cart variants). Booking-access is gated by certification. | search + redirect only (no booking file) | **GAP — provider supports E2E; we don't call it** |
| **Tiqets** (tours/tiqets) | **YES** — Distributor/Booking API creates & cancels orders on your own checkout. Gated (approval; ~200 orders/mo threshold). | search + redirect (returns checkout URL) | **GAP — provider supports E2E (gated)** |
| **Booking.com** (stays/booking) | **YES** — official **Demand API** for affiliate partners can "check availability, manage bookings" (create reservations). (Note: new *Connectivity* provider onboarding is paused; Demand API is the demand-side path.) | RapidAPI 3rd-party wrapper, search only | **GAP — official Demand API supports booking; our wrapper doesn't** |
| **Agoda** (stays/agoda) | **YES but gated** — B2B/Demand API has search → (PreCheck) → **Book** → cancel. Not self-serve; requires direct BD onboarding. YCS is supply-side, not for OTAs. | affiliate Lightweight search + `landingURL` redirect | **GAP (gated) — B2B Book API exists; we use affiliate only** |
| **Amadeus hotels** (stays/amadeus) | **YES** — Amadeus Hotel Booking API exists (same account family already used for flights). | search/offers read-only, no booking file | **GAP — bookable API, not wired for booking** |
| **KiwiTaxi** (cars/kiwitaxi) | **YES** — KiwiTaxi has a **partner API to order transfers** (documented partner/order API), in addition to the affiliate program. | search only, no booking file | **GAP — order API exists; we use search/affiliate only** |
| **DiscoverCars** (cars/discover_cars) | **Partly** — public API (`api-partner.discovercars.com`) is affiliate: locations + car search by location/date; booking is predominantly via affiliate redirect. | search only, no booking file | **Mostly provider-nature (affiliate); booking not a standard self-serve API** |
| **Travelpayouts** (flights/travelpayouts) | **NO** — affiliate network; you request a `terms.url` redirect link to the agency/airline. Its old booking API is **deprecated**. | empty `issue.php` stub; search returns redirect | **Provider-nature (affiliate). The empty stub is consistent — but it should return the redirect link, which it doesn't.** |
| **Google Flights** (flights/googleflights) | **NO** — there is no official public Google Flights booking API. | redirect + fake PNR | **Provider-nature — redirect is the only possible model, NOT a gap** |
| **AirHelp** (insurance/airhelp) | **YES** — AirHelp Partner API (`partner-api.airhelp.com/v2`) creates bookings. | real code exists but **only in standalone test files, not wired into the app** | **GAP — provider supports it; our integration is not connected** |

**Bottom line of the cross-check:**
- **Real, fixable gaps** (provider API supports E2E booking; we only search/redirect
  or left it disconnected): **Viator, Tiqets, Booking.com, Agoda, Amadeus-hotels,
  KiwiTaxi, AirHelp.** Several are *gated* (Viator/Tiqets/Agoda/Booking.com require
  partner approval), so "closing the gap" means both engineering **and** a
  commercial agreement with the provider.
- **Provider-nature, not a gap** (no booking API to call, redirect is the intended
  model): **Google Flights, Travelpayouts, DiscoverCars** (largely).
  - Even so, **Travelpayouts** should at minimum surface the affiliate redirect
    link on "book"; today its `issue.php` is an empty stub, so the affiliate flow
    is effectively broken for it.

**Sources (Aug 2026):**
- Viator Partner API — https://docs.viator.com/partner-api/technical/ ; partner resource center https://partnerresources.viator.com/travel-commerce/technical-guide/
- Tiqets Distributor/Booking API — https://portals.tiqets.com/distributorapi/docs ; booking-API access https://partners.tiqets.com/en_us/how-do-i-become-a-tiqets-distributor-api-partner-with-booking-api-access-HykGNahGj
- Booking.com Demand API — https://developers.booking.com/demand ; Connectivity docs https://developers.booking.com/connectivity/docs
- Agoda Demand/B2B — https://developer.agoda.com/demand/docs/getting-started ; YCS FAQ https://www.agodaconnectivity.com/documentation/faq/faq-ycs-5-api
- KiwiTaxi partner/order API — https://github.com/liderman/go-kiwitaxi-api ; travel-agency program https://kiwitaxi.com/en/travelagency
- DiscoverCars affiliate API — https://support.travelpayouts.com/hc/en-us/articles/360012655219-DiscoverCars-affiliate-program-API
- Travelpayouts (affiliate; booking API deprecated) — https://support.travelpayouts.com/hc/en-us/articles/206111067-API-of-affiliate-booking-balance-and-payment-deprecated
- Google Flights (no official booking API) — https://www.scrapingbee.com/blog/top-flights-apis-for-travel-apps/

> These provider capabilities were researched from public docs in Aug 2026 and
> can change. They describe what the provider *offers*; the code-verified sections
> above (§3–§6) describe what this platform *actually does today*.

---

*Prepared from direct code reads. Verdicts marked "Verified" were confirmed by me
via grep/Read; agent-sourced robustness leads are labelled as such. No supplier
was assumed — every (A)/(B)/(C)/(D) maps to a real file that was opened.*

---

## 8. REAL-API provider deep audit (Phase 2 — implementation correctness)

**What this section answers:** for every module classified **REAL API BOOKING** in
§3, is the booking lifecycle (search → issue/book → cancel → refund) actually
implemented *correctly and completely*, or are there gaps/bugs that would break a
real paid booking? This goes beyond §3 (which only asked "does it call a real
booking endpoint?") to ask "does that call work, verify price, store the right
reference, cancel for real, and refund the customer?"

**Method & honesty statement.** Each of the 22 real-API modules was read in full by
a dedicated pass (issue/cancel/refund/void/revalidate + search/creds/api/lib).
Provider API endpoints were cross-checked against current public docs (Sept 2026;
sources at the end of this section). **I then independently re-verified, by direct
grep/Read of the cited files, the most severe and most surprising findings** —
those spot-checks are marked **✓verified** below. Findings I did *not* personally
re-open are marked **(reported)** and should be treated as high-confidence leads to
confirm at the exact line before acting, not as personally-audited facts. Line
numbers are from the current tree and can shift with edits.

> **Scope limit (stated, not hidden):** this is static analysis. No live booking
> was executed against any supplier sandbox. "Complete/partial/skeleton" describes
> what the code *does*, not whether a real transaction succeeds end-to-end.

### 8.1 Two systemic problems affecting nearly EVERY real-API provider

These are not per-provider quirks — they are platform-wide patterns found across
the whole real-API set. They are the two most important takeaways of Phase 2.

**(1) No provider actually refunds the customer's money.** Across **all** real-API
modules that have a `refund.php`, the refund is a **database status flip only**
(`payment_status = 'refunded'`) — **not one** module calls the payment gateway
(Stripe/PayPal/Paystack/Cashfree/Adyen) to reverse the customer's card charge.
Several modules have **no refund path at all** (amadeus, cartrawler, mozio, kikoto
have no `refund.php`; tbo/pkfare `refund.php` do nothing or are duplicates). This
confirms and sharpens §6's earlier finding: **returning money to the buyer is
always a manual action an admin must perform in the payment provider's dashboard.**
The UI/staff are frequently told "refunded" when no money has moved. Some modules
say so in their own comments (amadeus_enterprise `refund.php:13`, tbo-holidays
`refund.php:88`). **✓verified** by grep across all `refund.php` (zero gateway-refund
calls) and by reading the specific DB-flip lines.

**(2) No provider re-checks price against what the customer paid before booking.**
Nearly every `issue.php` re-fetches or re-prices with the supplier *after* payment,
but **none compare that live supplier price to the amount the customer actually
paid.** So if the fare/rate moved between checkout and ticketing:
- a price **increase** is silently absorbed (merchant eats it, or over-books at a
  higher net), and
- a price **decrease** silently overcharges the customer with no refund.

Suppliers with a server-side tolerance (Hotelbeds `tolerance=2.00`, RateHawk
`price_increase_percent=20`) will *book within that band without telling anyone*.
This is the booking-side twin of security finding **H3** (price tampering) and is
present in duffel, amadeus, amadeus_enterprise, kiwi, mystifly, pkfare, sabre,
seeru, tbo, travelport, hotelbeds, ratehawk, stuba, hotelston, wanderbeds,
cartrawler, mozio, kikoto. **(reported for most; the "no comparison" pattern
✓verified in duffel, seeru, stays/travelport.)**

**Other recurring patterns:** missing supplier **idempotency keys** (a retry/
double-submit after payment can double-book — duffel, amadeus, kiwi, sabre, tbo,
seeru, stuba, cartrawler, wanderbeds, kikoto); **TLS verification disabled**
(`CURLOPT_SSL_VERIFYPEER=false`) on live supplier calls (**✓verified**: cartrawler
`issue.php:278`, rail/train `search.php:280`, stuba `issue.php:712`, hotelston
`issue.php:148`, seeru `issue.php:187-188`); and **"confirmed" set even when
ticketing failed** (tbo non-LCC, rail/train, stuba-on-exception).

### 8.2 The four provider modules that are actually BROKEN (not just imperfect)

These were rated `skeleton`/dead and I **✓verified each one by hand** — they will
fail or mis-behave for a real paid booking today:

- **flights/sabre — `skeleton`. ✓verified.** `issue.php` (494 lines) builds a
  `CreatePassengerNameRecordRQ` with `AgencyInfo` + `CustomerInfo` + passenger
  names **but no flight segments / priced itinerary** (no `OriginDestination` /
  `AirBook` in the payload — confirmed by grep). It therefore cannot create a real
  air PNR, and there is **no ticketing step** (no AirTicket/EndTransaction) even on
  success. Real Sabre booking needs PassengerDetailsRQ → EnhancedAirBookRQ →
  ticketing (per Sabre docs). **Selling sabre flights would take payment and issue
  nothing.**
- **flights/travelport — refund/void route fatals. ✓verified.** `log_cert()` is
  declared as a plain function in **both** `actions/refund.php:8` and
  `actions/void.php:8`, and `index.php:10-11` includes both → **`Cannot redeclare
  log_cert()` fatal** at load. Additionally `actions/cancel.php:13` calls ~8
  helper functions (`travelport_call`, `travelport_build_cancel_offer_payload`, …)
  that are **defined in 0 files** and cancel.php does not include `helpers.php` →
  fatal on invocation. Travelport-flights cancel/refund/void are non-functional.
- **stays/travelport — `skeleton`, booking is dead code. ✓verified.** `issue.php`
  has a leftover **`die('here')` at line 314**; the file is 418 lines, so all
  response-parsing and the DB confirm/PNR-store after line 314 never run. The
  booking is never recorded. Also hardcodes hotel location `'DXB'` for every
  booking (`issue.php:92`) and builds amounts like `'USD149'` (invalid Travelport
  decimal). **stays/travelport cannot complete a booking.**
- **flights/pkfare — refund is a copy of issue; cancel is dead. ✓verified.**
  `actions/refund.php` is a **byte-for-byte identical copy** of `actions/issue.php`
  (same MD5 `bf6f84…`) and re-registers `POST flights/pkfare/issue` — so there is
  **no refund logic at all** and a **route collision** (two files register the same
  route). `actions/cancel.php` is a duplicate of `search.php` (registers
  `.../search`) and isn't included by `index.php`, so pkfare has **no working
  cancel** either.

### 8.3 Per-provider completeness (all 22 real-API modules)

Completeness = how much of the search→issue→cancel→refund lifecycle is correctly
implemented. "Refund" column = does refund actually return the customer's money?
(**No** everywhere — see §8.1(1).)

| Module | Complete­ness | Issue/book | Cancel (real supplier call?) | Refund returns money? | Most serious issue |
|---|---|---|---|---|---|
| flights/duffel | partial | ✓ `POST /air/orders` (instant, balance) | ✓ real (`order_cancellations`) | ✗ DB-flip only | refund DB-only; fake `payment_intent_id`; no idempotency |
| flights/amadeus | partial | ✓ `POST /v1/booking/flight-orders` | ✓ via void (order cancel) | ✗ no refund.php at all | held order (DELAY_TO_CANCEL 6D) marked "confirmed" but **unticketed**; placeholder passport `XXXXXXXXX` |
| flights/amadeus_enterprise | mostly-complete | ✓ flight-orders (+fallback) | ✓ real | ✗ DB-flip only (admits it) | same DELAY_TO_CANCEL "confirmed-but-unticketed"; fabricated pax docs |
| flights/kiwi | partial | ✓ `POST /v2/booking` | ✗ **DB-only flip** | ✗ manual instructions only | no revalidation of stale `booking_token`; cancel doesn't hit supplier |
| flights/mystifly | mostly-complete | ✓ `Book/Flight` (+OrderTicket) | ⚠ endpoint is a **TODO guess** | ✗ PTR + DB-flip | **fabricates `PENDING-…` ref and marks CONFIRMED** when supplier didn't confirm |
| flights/pkfare | **broken** | ✓ `preciseBooking_V6` (but books w/o payment check) | ✗ **dead dup file** | ✗ **refund = copy of issue** | refund/cancel non-functional; route collision |
| flights/sabre | **skeleton** | ✗ **no flight segments in payload; no ticketing** | ✓ (builds cancel) | ✗ `refund_pending` + manual | cannot create a real PNR; issues nothing |
| flights/seeru | mostly-complete | ✓ fare→save→issue | ✓ real | ✗ supplier-only + DB-flip | **SSL verify disabled**; books `price_increased` w/o compare; open routes no auth |
| flights/tbo | partial | ✓ `Booking/Book`+`Ticket` | ✗ only sets `cancellation_request=1` | ✗ **stub returns false** | non-LCC marked "confirmed" even when Ticket errored |
| flights/travelport | partial→**broken** | ✓ workbench reservation | ✗ **fatal (undefined fns)** | ✗ **fatal (redeclare)** + DB-flip | refund/void/cancel routes fatal at load/invoke |
| stays/hotelbeds | mostly-complete | ✓ `POST /bookings` (CheckRate, tol 2%) | ✓ real | ✗ DB-flip only | refund DB-only; duplicate cancel route |
| stays/ratehawk | mostly-complete | ✓ prebook→finish→poll | ✓ real (`order/cancel`) | ✗ supplier-cancel + DB-flip | books net-rate up to +20% w/o compare; 600s sync poll can orphan booking |
| stays/stuba | mostly-complete | ✓ SOAP prepare→confirm | ✓ real | ✗ DB-flip only | **catch block force-sets `confirmed` on ANY error**; SSL disabled |
| stays/hotelston | partial | ✓ SOAP `bookHotel` | ✓ real | ✗ DB-flip only | **plaintext HTTP + SSL disabled** (creds in clear); no auth/CSRF on routes |
| stays/tbo-holidays | mostly-complete | ✓ PreBook→Book | ✓ real (`Cancel`) | ✗ DB-flip (no TBO refund API) | `sleep(120)` in request can orphan booking on proxy timeout |
| stays/wanderbeds | mostly-complete | ✓ `hotel/book`→`bookinfo` | ✓ real (`hotel/cancel`) | ✗ DB-flip only | no post-pay price compare; weak idempotency |
| stays/travelport | **skeleton** | ✗ **`die('here')` kills book flow** | ⚠ marks cancelled regardless of API result | ✗ `refund_status=pending` only | booking never recorded; hardcoded `DXB`; column-name mismatch across lifecycle |
| cars/cartrawler | partial | ✓ `OTA_VehResRQ` (+ confirm) | ✓ real (`OTA_CancelRQ`) | ✗ **no refund path** | books stale reference, no re-quote; SSL disabled |
| cars/mozio | mostly-complete | ✓ `POST /v2/reservations/` (+poll) | ✓ real (`DELETE /reservations`) | ✗ **no gateway refund** | ~15s poll can leave paid booking "pending"; no refund for gateway-captured pay |
| ferries/kikoto | mostly-complete | ✓ create→`/confirm` | ✓ real (`/cancel`) | ✗ **no refund.php** | no price re-verify; no idempotency |
| rail/train | mostly-complete | ✓ `POST /ticket/order` | ✓ real (`orderCancel`) | ✗ supplier-only, no gateway | **SSL disabled**; force-marks "confirmed" on `fail_msg` |
| esim/airalo | partial | ✓ `POST /v2/orders` (OAuth) | ✓ (cancel present) | ✗ no gateway refund | (partner-gated API; docs limited — code-verified only) |

### 8.4 What is genuinely solid (preserve)

- **Real supplier cancellation exists and works** for most modules (duffel,
  amadeus(void), amadeus_enterprise, seeru, hotelbeds, ratehawk, stuba, hotelston,
  tbo-holidays, wanderbeds, cartrawler, mozio, kikoto, rail/train) — the cancel
  call really reaches the supplier. The gaps are in kiwi/tbo (DB-only cancel) and
  pkfare/travelport (broken).
- **Duffel, Amadeus, RateHawk, Hotelbeds, TBO-Holidays, Mozio, Airalo** call the
  correct, current supplier endpoints (verified against Sept-2026 provider docs).
- **Hotelbeds** correctly implements CheckRate/rateKey and a timeout→reconcile
  path (`needs_reconcile`) instead of blind retry — the best-behaved stays module.

### 8.5 Priority fixes (booking-side, distinct from the security-audit list)

1. **Wire real gateway refunds** (or stop telling users "refunded"): add a payment-
   gateway reversal step to the refund flow platform-wide — this is the single
   biggest correctness gap (§8.1(1)).
2. **Add post-payment price reconciliation** before every supplier commit: compare
   live supplier price to amount paid; abort/hold on mismatch beyond a tolerance
   (§8.1(2)).
3. **Fix the four broken modules** (§8.2): sabre (add segments+ticketing),
   flights/travelport (`log_cert` redeclare + undefined cancel helpers), stays/
   travelport (remove `die('here')`), pkfare (write a real refund/cancel).
4. **Stop marking bookings "confirmed" on failure** — stuba catch-block, tbo
   non-LCC, rail/train `fail_msg`.
5. **Re-enable TLS verification** on all live supplier calls (seeru, stuba,
   hotelston, cartrawler, rail/train).
6. **Add supplier idempotency keys / already-issued guards** to prevent double-
   booking on retry.

**Sources (provider APIs, Sept 2026):** Duffel Orders — https://duffel.com/docs/api/orders ;
Amadeus Flight Create Orders — https://developers.amadeus.com/self-service/category/flights/api-doc/flight-create-orders ;
Sabre CreatePassengerNameRecord / Issue Ticket — https://developer.sabre.com/docs/rest_apis/air/book/create_passenger_name_record ;
Hotelbeds APItude CheckRate/Booking — https://developer.hotelbeds.com/documentation/hotels/booking-api/ ;
RateHawk ETG v3 booking — https://docs.emergingtravel.com/docs/affiliate-api/booking/create-booking-process/ ;
Travelport JSON Air booking — https://support.travelport.com/webhelp/JSONAPIs/Airv11/Content/Air11/Book/BookingGuide.htm ;
CarTrawler OTA_VehRes — https://phptravels.com/cartrawler-api-integration ;
TBO Holidays PreBook/Book — https://api.tbotechnology.in/AIS_APISpecification.aspx ;
Kiwi Tequila (invite-only 2026) — https://tequila.kiwi.com/ ;
Mystifly OnePoint — https://mystifly.com/ .

> **Verification legend for §8:** **✓verified** = I re-opened the cited file/lines
> myself during this pass (all of §8.2, §8.1's refund/SSL claims, and the "no price
> compare" pattern in duffel/seeru/stays-travelport). **(reported)** = surfaced by
> the per-module read pass and consistent with the file inventory, but not
> personally re-opened line-by-line — confirm at the exact line before acting.

---

## 9. Affiliate / stub provider upgrade audit (Phase 3 — can we make them book?)

**What this section answers:** for every provider the platform does **not** book
through today (the AFFILIATE/REDIRECT and STUB modules), does the provider actually
offer a real end-to-end booking API we could **upgrade** to — turning it into a
REAL-API module — or is affiliate/redirect the only model the provider supports?
The first is a **fixable gap** (engineering ± a commercial agreement); the second
is **provider-nature** (nothing to build).

**Method & honesty.** For each provider I (a) re-verified in code what we ship today
(**✓code-verified** below), and (b) researched the provider's current public API
capability, gating, and deprecations (**web-researched Sept 2026**; sources at the
end). The code side is fact; the provider-capability side describes what the
provider *offers* and can change — treat it as a lead for a commercial/technical
scoping call, not a guarantee. This section supersedes and refreshes the older §7
(which was Aug-2026 and did not cover kayak, rezlive, expedia).

### 9.1 What we ship today for each (✓code-verified this pass)

| Module | Class | Files present | Books today? |
|---|---|---|---|
| flights/googleflights | affiliate | search + issue/cancel/refund/void | **No** — `issue.php:4` says "there is no booking API"; returns redirect + fake PNR |
| flights/kayak | affiliate | creds, index, **search only** | **No** — no issue file; KAYAK affiliate *search* API |
| flights/travelpayouts | stub | search + **empty `issue.php`** | **No** — `issue.php` is `// TODO` (verified, still empty) |
| stays/agoda | affiliate | creds, index, **search only** | **No** — returns `landingURL` redirect |
| stays/amadeus | affiliate | search, details, rooms (**no booking**) | **No** — read-only hotel offers |
| stays/booking | affiliate | search, details, rooms (**no booking**) | **No** — 3rd-party RapidAPI wrapper |
| tours/viator | affiliate | search, details (**no booking**) | **No** — returns Viator checkout redirect |
| tours/tiqets | affiliate | search, details (**no booking**) | **No** — returns Tiqets checkout URL |
| tours/viator_merchant | stub | creds + index only (364 lines total) | **No** — no search/details/issue |
| cars/discover_cars | affiliate | creds, index, **search only** | **No** — no booking file |
| cars/kiwitaxi | affiliate | creds, index, **search only** | **No** — no booking file |
| insurance/airhelp | ~~stub~~ **real (§11)** | index.php + insurance routes/views (**now wired**) | **Yes** — registers a claim via AirHelp v2; free to customer; needs Partner Token to fire live |
| stays/rezlive | stub | **no module directory** (DB row only) | **No** — not integrated at all |
| stays/expedia | stub | **no module directory** (DB row, inactive) | **No** — not integrated at all |

### 9.2 Upgrade verdict per provider (web-researched Sept 2026)

**★ REAL, FIXABLE GAPS — provider has a full booking API; we only search/redirect.**
These can become REAL-API modules (engineering + the noted gating/commercial step):

| Provider | Provider booking API (current) | Gating | Head-start we already have | Effort verdict |
|---|---|---|---|---|
| **stays/amadeus** | **`POST /v2/booking/hotel-orders`** (self-service Hotel Booking API v2; guests/roomAssociations/paymentCard) | ⚠ **CARD-REQUIRED** — self-service API needs a credit card IN the booking payload; **no agency-account settlement** (Enterprise-only). See §10. | search/details/rooms built; OAuth + creds pattern identical to flights amadeus | **RE-ASSESSED — not the easy win first claimed.** Upgrade attempted and **paused** (§10): our gateway-capture model doesn't fit the card-in-payload requirement without PCI scope, a VCC, or Amadeus Enterprise. |
| **tours/viator** | `/availability/check` → `/bookings/cart/hold` → book (+cancel) | **Certification** (Full+Booking access; must implement `/cart/hold`) | search + details built against `api.viator.com/partner` | Medium — needs Viator cert |
| **tours/tiqets** | Distributor/Booking API: create→confirm→cancel + webhooks | **Performance-gated** (~200 orders/mo; self-service token, Booking API on-demand) | search + details built; token self-service | Medium — needs volume threshold |
| **stays/booking** | **Demand API** `/orders/preview` + `/orders/create` (+manage/cancel) | **Managed Affiliate Partner** + Partner Centre + API key/`X-Affiliate-Id` | our current wrapper is a 3rd-party RapidAPI *search* — would need re-integration against official Demand API | Medium-high — re-integrate + partner status |
| **stays/agoda** | Mandatory **Book API** + optional Precheck + 3-step cancel | **Not self-serve** — direct BD onboarding + certification test booking | affiliate lite search + `landingURL` built | High — commercial onboarding required |
| **cars/kiwitaxi** | Full **partner order API** (order transfers, modify/cancel, vouchers, net rates) | Partner program (BD contact) | search built | Medium — wire order API + partner contract |
| **stays/rezlive** | **RezTez XML/JSON API** — live rates, availability, **booking confirmations**, 500k+ hotels | B2B wholesaler agreement | **nothing built** (DB row only) — greenfield | Medium-high — build module from scratch + contract |
| **insurance/airhelp** | AirHelp Partner API (`partner-api.airhelp.com/v2`) creates claims/orders | Partner API key | **create_order.php/get_order.php already written** — just not wired into `modules/index.php`/routes; creds are placeholders | **Low-ish** — wire existing code + real key. NOTE: AirHelp is flight-**compensation-claim**, a different product than travel insurance — confirm it's the product you intend to sell |

**● PROVIDER-NATURE — no self-serve booking API to call; redirect is the model.**
Not a gap; the fix (if any) is just to make the redirect/affiliate flow work:

| Provider | Why it stays affiliate | Action (if any) |
|---|---|---|
| **flights/googleflights** | No official Google Flights **booking** API exists — price comparison only | Keep redirect; nothing to upgrade to |
| **flights/kayak** | KAYAK official API is **affiliate Flights *Search*** only, partner-gated; booking is a redirect to the provider | Keep redirect; correct as-is |
| **flights/travelpayouts** | Affiliate network; its old **booking API is deprecated** — you request a `terms.url` redirect link | **Fix the broken stub:** `issue.php` should return the affiliate `terms.url` redirect; today it is empty so the affiliate flow doesn't work at all |
| **cars/discover_cars** | Public API (`api-partner.discovercars.com`) is predominantly **affiliate** (locations + search); booking is affiliate-redirect | Optionally surface the affiliate redirect; not a self-serve booking API |

**◐ DUPLICATE / MISLABELED**

| Module | Reality |
|---|---|
| **tours/viator_merchant** | Uses the **same Viator Partner API** as tours/viator but under Viator's *merchant* (net-rate) model. It has only `creds.php`+`index.php` — non-functional. If you pursue Viator booking (above), decide **merchant vs affiliate** model and consolidate; don't build both. |
| **stays/expedia** | Inactive DB row, no code. Expedia's **Rapid API** (EPS) *does* offer real shop→price→book, but this platform has **zero** integration — greenfield if ever pursued (not researched in depth this pass; flagged, not verified). |

### 9.3 Recommended upgrade order (highest value / lowest friction first)

1. ~~**stays/amadeus → REAL**~~ **— ATTEMPTED & PAUSED (see §10).** The
   self-service Hotel Booking API requires a **credit card in the booking
   payload** and does **not** support agency-account settlement, so it does not
   fit our "capture via our gateway, book on account" model without PCI scope, a
   virtual-card provider, or Amadeus **Enterprise**. My earlier "no gating, best
   ROI" call was **wrong** — corrected here.
2. ~~**insurance/airhelp → REAL (wire-up)**~~ **— DONE ✓ (§11).** Built the full
   `insurance` service + wired AirHelp claim registration; verified live. Only
   remaining step is provisioning a real AirHelp Partner Token (commercial, not
   code) — until then it records claims locally and no-ops the remote call.
3. **tours/tiqets** and **tours/viator → REAL** — both have booking APIs and we
   already have search/details; gated by (Tiqets) volume and (Viator) certification.
4. **cars/kiwitaxi → REAL** — order API exists, search built; needs partner contract.
5. **stays/booking → REAL** — highest inventory value but requires re-integrating
   against the official Demand API (drop the RapidAPI wrapper) + Managed Affiliate
   Partner status.
6. **stays/agoda / stays/rezlive** — real APIs but heaviest lift (agoda = BD
   onboarding; rezlive = greenfield build).
7. **Leave as affiliate:** googleflights, kayak, discover_cars. **Just fix:**
   travelpayouts `issue.php` (return the affiliate redirect link).

**Sources (provider APIs, Sept 2026):**
Amadeus Hotel Booking v2 (`/v2/booking/hotel-orders`) — https://developers.amadeus.com/self-service/apis-docs/guides/developer-guides/migration-guides/hotel-booking/ ;
Viator Partner API (availability/check, cart/hold, certification) — https://docs.viator.com/partner-api/technical/ , https://partnerresources.viator.com/travel-commerce/certification/ ;
Tiqets Distributor/Booking API (create/confirm/cancel, ~200 orders/mo gate) — https://portals.tiqets.com/distributorapi/docs , https://partners.tiqets.com/en_us/how-do-i-become-a-tiqets-distributor-api-partner-with-booking-api-access-HykGNahGj ;
Booking.com Demand API (`/orders/preview`, `/orders/create`) — https://developers.booking.com/demand/docs/open-api/demand-api ;
Agoda Demand/B2B (Book API + Precheck + cancel; BD onboarding) — https://developer.agoda.com/demand/docs/json-book-api , https://developer.agoda.com/demand/docs/getting-started ;
KiwiTaxi partner order API — https://kiwitaxi.com/en/travelagency , https://github.com/liderman/go-kiwitaxi-api ;
RezLive RezTez XML/JSON — https://www.rezlive.com/staticpagesrezlive/xml-connect.html ;
AirHelp Partner API — https://www.travelpayouts.com/blog/airhelp/ ;
DiscoverCars affiliate API — https://support.travelpayouts.com/hc/en-us/articles/360012655219-DiscoverCars-affiliate-program-API ;
Travelpayouts booking API deprecated — https://support.travelpayouts.com/hc/en-us/articles/206111067-API-of-affiliate-booking-balance-and-payment-deprecated ;
KAYAK affiliate Flights Search API — https://affiliates.kayak.com/apis/flights ;
Google Flights (no official booking API) — https://www.scrapingbee.com/blog/top-flights-apis-for-travel-apps/ .

> **Honesty note for §9:** the "what we ship today" column is code-verified this
> pass. The provider-capability verdicts are from public docs (Sept 2026) and
> describe what each provider *offers* — several are gated behind partner approval,
> certification, or volume thresholds, so "closing the gap" is engineering **plus**
> a commercial step, not code alone. Expedia's EPS capability is noted but was not
> deep-verified. Nothing here was assumed about our own code without opening it.

---

## 10. Upgrade attempt log — stays/amadeus (PAUSED, not built)

**Goal:** upgrade `stays/amadeus` from affiliate/search-only to a REAL-API booking
module using Amadeus's self-service **Hotel Booking API v2**
(`POST /v2/booking/hotel-orders`), so a paid hotel booking is actually created at
Amadeus after checkout — the "best ROI" candidate from §9.3.

**Outcome: PAUSED before any code was written.** A blocking constraint was found
during grounding research that makes the self-service API a poor fit for this
platform's payment model. **No booking files were created;** `stays/amadeus`
remains search-only and its `booking_class` stays `affiliate`.

### 10.1 What I verified in our code first (so the plan was real, not assumed)
- **Auth/creds pattern (✓read):** `stays/amadeus/search.php:51-76` — `c1`=client_id,
  `c2`=client_secret, `env`/`dev_mode` selects `api.amadeus.com` vs
  `test.api.amadeus.com`, OAuth2 via `/v1/security/oauth2/token`, then Bearer.
  Identical to the working flights amadeus module — reusable.
- **Bookable identifier (✓read):** `rooms.php:341` already returns each offer as
  `rate_key => $offer['id']` (the Amadeus `hotelOfferId`) — the exact id the v2
  booking needs.
- **Where issue.php would plug in (✓read):** the gateway auto-issues any non-
  excluded stays module by POSTing `invoice_id` to
  `root . 'modules/'.$moduleType.'/'.$module.'/issue'`
  (`app/lib/payment-gateway.php:381,388`). `amadeus`/`stays` is **not** in the
  exclusion list (`:368-371`), so a new `stays/amadeus/issue` route would be
  auto-called after payment **with zero gateway changes** — same path hotelbeds/
  ratehawk use.
- **Data available at issue time (✓read):** the booking row carries `booking_data`
  (hotel_id, `selected_rooms[]` with the offer `rate_key`, dates, supplier),
  `travellers` (guests), `user_data`, and `price_markup`/`currency_markup` (the
  amount charged) — everything a booking payload needs
  (`app/routes/stays/bookingRoutes.php:660-699`).

So the *mechanical* integration was fully scoped and low-risk. The blocker is not
our code — it is the provider's payment model.

### 10.2 The blocking constraint (why it was paused)
The v2 payload is `data.type='hotel-order'` with `guests[]`, `travelAgent`,
`roomAssociations[].hotelOfferId`, **and a `payment` object**. Per Amadeus's own
docs, the **self-service Hotel Booking API only books hotels that accept a credit
card, and the card must be supplied in the booking request** (as Guarantee,
Deposit, or Prepay — all require card details). **Agency-account / "book on our
balance" settlement is NOT available on self-service — it exists only on Amadeus
Enterprise.**

This platform's model (verified across every real stays module in §8) is:
**capture the customer's money via our own payment gateway, then book the supplier
on the platform's account/balance — the customer's raw card is never held.** That
model has **no card to put in the Amadeus payload.** So self-service Amadeus hotel
booking cannot be wired in cleanly without one of:
1. **PCI scope** — collect/store/pass the customer's real PAN to Amadeus (large
   PCI-DSS burden; conflicts with the gateway model);
2. **A merchant card / virtual-card (VCC)** as the form of payment to Amadeus
   after our gateway captures the customer (realistic, but needs a real card/VCC
   provider configured — a commercial + engineering step);
3. **Amadeus Enterprise API** — which *does* support agency-account settlement and
   fits "book on account" natively.

### 10.3 Decision
**Paused, pending Amadeus Enterprise** (option 3). A clean book-on-account flow —
the one that matches how every other real stays supplier here settles — requires
the Enterprise API, not self-service. Building the self-service card flow was
explicitly declined to avoid shipping a PCI/card-handling path that doesn't fit
the platform. This also **corrects §9.3**, which had ranked stays/amadeus as the
"no gating, lowest-friction, best ROI" upgrade — that was wrong: the gating is a
payment-model/commercial one (Enterprise contract or a VCC provider), not "none."

**To resume later, the decision needed is commercial, not technical:** obtain
Amadeus **Enterprise** access (then `hotel-orders` with agency-account FOP), OR
stand up a **VCC provider** to fund each booking. Once either exists, §10.1 shows
the integration itself is small (add `actions/issue.php` mirroring hotelbeds,
reuse the existing OAuth, add post-payment price re-confirm via
`GET /v3/shopping/hotel-offers/{offerId}` before booking — closing the §8.1(2)
price-check gap by design — and store the returned `hotel-order` id as the PNR).

**Sources (Sept 2026):** Amadeus Hotel Booking v2 request body / offerId→
`roomAssociations.hotelOfferId`, endpoint `/booking/hotel-orders` —
https://developers.amadeus.com/self-service/apis-docs/guides/developer-guides/migration-guides/hotel-booking/ ;
Hotel APIs tutorial (payment: card-only; Guarantee/Deposit/Prepay; agency accounts
are Enterprise) — https://developers.amadeus.com/self-service/apis-docs/guides/developer-guides/resources/hotels/ ;
Amadeus Enterprise Hotel Booking — https://developers.amadeus.com/enterprise/category/hotel/api/booking .

> **Honesty note for §10:** nothing was built or shipped. The code-side facts in
> §10.1 are from files I opened this pass; the payment constraint in §10.2 is from
> Amadeus's public docs (Sept 2026) and is the specific reason the upgrade was
> halted. I explicitly corrected my own earlier §9 "best ROI / no gating"
> recommendation rather than leaving the mistake in place.

---

## 11. Build log — insurance service + AirHelp integration (COMPLETE ✓)

**Goal (as instructed):** build the entire missing **`insurance` service** (frontend,
flow, checkout, DB module row) **and** wire the **AirHelp** integration into it,
turning the two standalone AirHelp test files into a real, framework-integrated
feature.

### 11.1 What AirHelp actually is (code-verified — this shapes the whole design)
Confirmed by reading `modules/insurance/airhelp/create_order.php` (docblock lines
25-35) and `get_order.php`:
- AirHelp is a **post-flight flight-compensation-CLAIM** service, **not** a
  policy the customer buys. **The customer is never charged.** AirHelp keeps a 35%
  service fee from the airline payout and pays the platform **~10.5% of the payout
  per won claim** (commission to us).
- The Partner API v2 "Booking" (`POST /v2/booking/{identifier}`) registers a
  passenger + flight(s) so AirHelp can pursue compensation if the flight is
  disrupted. It is **asynchronous (HTTP 202)** and **push/event based** (no
  documented GET retrieval on all tiers).
- Therefore this is **NOT** an "issue-after-payment" booking. It is a **free claim
  registration**. It must NOT be hooked into the payment-gateway auto-issue path
  (that path is for paid supplier bookings). Design accordingly.

### 11.2 State before this build (code-verified)
- `modules/insurance/airhelp/` = only `create_order.php` + `get_order.php`, both
  **standalone HTML test pages** (0 uses of `$db`/`$router`/`$SECURE`; static
  sample data; file-based `last_order_id.txt`; placeholder Bearer token).
- **No `insurance` service in the app**: no `app/routes/insurance/`, no views, no
  nav entry, not registered in `_routes.php` or `modules/index.php`.
- **No DB `modules` row** for airhelp (verified: `SELECT … WHERE type='insurance'`
  returns nothing). `insurance` IS already a valid `modules.type` enum value.
- Frontend services render from `$GLOBALS['modules']` + an icon/label map in
  `app/views/includes/header.php:110-145` (no `insurance` entry yet).

### 11.3 Design decision (the honest model)
Because the customer pays nothing for AirHelp, the product built is a **free
"Flight Compensation Claim" registration**:
- Customer submits a disrupted/eligible flight + passenger details → a **zero-price
  `bookings` row** (`module_type='insurance'`, `module='airhelp'`, `price=0`,
  `payment_status='free'`) → we call AirHelp `POST /v2/booking/{ref}` to register
  the claim → store AirHelp's identifier as the `pnr`/reference → track status.
- **No payment gateway, no auto-issue hook.** The AirHelp call fires at
  registration time (or via an admin action), not after a payment callback.
- The integration **no-ops safely** until a real AirHelp Partner Token +
  Booking Source are configured in the module row (so it never sends fake creds to
  production; it records the claim locally and marks it "pending credentials").

### 11.4 Build result (files) — ALL LANDED ✓
1. **DB module row** — ✓ inserted `airhelp`/`insurance` into `modules` (live id 51,
   `status=1`, `booking_class='real'`) **and** into `install/db.sql` seed (id 69)
   for fresh installs.
2. **`modules/insurance/airhelp/index.php`** — ✓ NEW. Framework module with
   `airhelp_register_claim($db,$invoiceId)`, `airhelp_is_configured()`,
   `airhelp_request()` (SSL **on**), `airhelp_uuid_v4()`, and route
   `POST insurance/airhelp/issue`. Reads creds from the module row (c1=token,
   c2=partner_id, c3=booking_source, dev_mode=staging/prod). Builds the exact v2
   payload (`data.booking` + flights + passengers).
3. **`modules/insurance/airhelp/index.php` credential handling** — ✓ folded into
   the module (reads modules row). A dedicated admin creds screen can be added
   later; not required for function.
4. **`app/routes/insurance/homeRoutes.php`** — ✓ NEW. `GET /insurance` → claim form.
5. **`app/routes/insurance/bookingRoutes.php`** — ✓ NEW. `POST /insurance/claim/submit`
   → CSRF-checked, validates flight+passenger, creates a **zero-price** booking
   (`module_type='insurance'`, `payment_status='unpaid'`, `price=0`), then calls
   `airhelp_register_claim`.
6. **`app/routes/insurance/invoiceRoutes.php`** — ✓ NEW. `GET /insurance/claim/{id}`
   → status page **with an ownership check** (avoids the §-security C3 invoice-IDOR
   pattern).
7. **Wiring** — ✓ 3 route files registered in `app/routes/_routes.php`; module
   included in `modules/index.php`.
8. **Nav** — ✓ `'insurance' => 'health_and_safety'` icon + "Compensation" label in
   `app/views/includes/header.php`.
9. **Views** — ✓ `app/views/modules/insurance/home.php` (claim form + JS) and
   `claim.php` (status page).
10. **MODULES.md** — ✓ §3 + §9 airhelp rows flipped stub → real; this §11
    finalized.

### 11.5 Verification (done live against the running XAMPP app + DB)
- All 10 touched PHP files pass `php -l`. ✓
- **Live HTTP end-to-end:** `GET /insurance` → HTTP 200 with the claim form + CSRF
  token; `POST /insurance/claim/submit` (valid CSRF) → HTTP 200
  `{"status":true,"invoice_id":"…"}`, created the booking row
  (`module=airhelp, module_type=insurance, booking_status=pending,
  payment_status=unpaid, price=0, pnr=TESTPNR`); `GET /insurance/claim/{id}` →
  HTTP 200 status page. ✓
- **Safe no-op confirmed:** with placeholder creds the AirHelp remote call was
  skipped and the claim recorded locally ("awaiting AirHelp credentials") — **no
  fake credentials were ever sent.** ✓
- **CSRF enforced:** `POST` without a token → rejected
  ("Invalid or expired session token"). ✓
- Test booking deleted after verification. ✓

### 11.6 Two important truths (stated, not hidden)
- **ENUM constraint discovered & handled:** `bookings.booking_status` is
  `ENUM('confirmed','pending','cancelled')` and `payment_status` is
  `ENUM('paid','unpaid','refunded')` — neither has `pending_credentials`/`failed`/
  `free`. Writing those silently truncates to `''`. The code therefore keeps
  `booking_status` within the enum (`pending`/`confirmed`) and stores the richer
  AirHelp sub-state (`pending_credentials`/`failed`) inside
  `booking_response`/`error_response`; the claim view reads that sub-state. A free
  claim is `payment_status='unpaid'` with `price=0`.
- **Still needs a real AirHelp Partner Token to go live.** The integration is
  functional and wired, but AirHelp will not accept real claims until a Partner
  Token + Booking Source are provisioned by AirHelp's Implementation Team and
  entered into the module row (admin → Settings → Modules → airhelp: c1/c2/c3).
  Until then every claim is recorded locally and marked `pending_credentials` —
  by design, so nothing fake is transmitted. The module row is created with
  `status=1` on the live DB during this build; set it back to `0` if you don't
  want the "Compensation" service visible in the nav yet.

### 11.7 Unrelated pre-existing bug fixed to enable live testing
While testing I found the **whole app was returning HTTP 500 on every page**
(`GET /`, `/login`, everything) — unrelated to this work. Cause: an earlier
security-fix pass added comment lines to `.env` (lines 31/34/37, for the JWT /
remember-me / reset secrets) that contained **parentheses**, and this PHP build's
`parse_ini_file()` rejects `(`/`)` even inside comments → fatal in `config.php:247`
→ app-wide 500. **Fix:** reworded only those three comment lines to remove the
parentheses (dashes instead). **No secret values were touched** (JWT_SECRET still
64 chars; `.env` now parses to 19 keys). This restored the app so the insurance
flow could be verified over HTTP.

> **Honesty note for §11:** everything above was built and then verified live
> against the running app and MySQL. The one limitation — no live AirHelp calls
> until a real Partner Token is configured — is inherent to the provider
> (credentials are provisioned by AirHelp), not a shortcut; the code no-ops safely
> rather than faking a result.

---

## 12. Broken-provider re-scan + fixes (all services)

**Goal:** re-verify EVERY real-API provider across all services for genuinely
*broken* code (fatals, dead code, dup/copy files, undefined-function calls,
route collisions) — not trusting §8's static audit — then fix each confirmed one.

**Method (no guesswork):** every verdict below is backed by a mechanical check run
this pass — `php -l`, actual include/load-tests, `md5` file comparison, and
function-definition greps — not by re-reading §8. Where §8 was wrong, it is
corrected here.

### 12.1 Scan checks run across all 22 real-API providers
1. **Parse errors** (`php -l` every file) → **none** in any provider.
2. **Load-time fatals** (actually include each provider's files in index.php order)
   → see corrections below.
3. **Duplicate/copy files** (`md5`) → pkfare hit.
4. **Undefined-function calls** (grep each called `provider_*()` against defs) →
   travelport-cancel hit.
5. **Dead `die()/exit()` mid-flow** → stays/travelport hit.

### 12.2 CONFIRMED broken (verified this pass) — to fix
| # | Provider | Proof (mechanical) | Effect |
|---|---|---|---|
| 1 | **flights/pkfare** | `md5(actions/issue.php) == md5(actions/refund.php)` (identical); `actions/cancel.php` registers `flights/pkfare/search` and is **not** included by `index.php` | refund is a copy of issue (no refund) + **duplicate `/issue` route collision**; no working cancel |
| 2 | **stays/travelport** | `die('here')` at `actions/issue.php:314`; file is 419 lines | 105 lines of booking/confirm/PNR-store logic after line 314 never execute → booking never recorded |
| 3 | **flights/travelport (cancel)** | `actions/cancel.php` calls 12 `travelport_*` fns; **10 are undefined** anywhere in the module (only `travelport_get_token`,`travelport_normalize_pcc` exist in `helpers.php`) | `flights/travelport/cancel` fatals at runtime ("undefined function travelport_call()") |
| 4 | **flights/sabre** | `CreatePassengerNameRecordRQ` payload (`actions/issue.php:264-301`) contains only `AgencyInfo`+`CustomerInfo`+names — **no `AirBook`/flight segments**; **no** ticketing call (AirTicket/EndTransaction grep = 0) | cannot create a real air PNR; issues no ticket |

### 12.3 §8 CORRECTIONS (audit was wrong — stated plainly, not hidden)
- **flights/travelport refund/void "Cannot redeclare log_cert() fatal at load" → FALSE.**
  `log_cert()` is defined **inside** each route closure
  (`$router->post('flights/travelport/refund'/'void', function(){ … })`), so
  including both files only registers closures — **no redeclare on load**
  (verified: load-test returned LOADED_OK). It is a *latent runtime* redeclare risk
  only if both routes were to run in the same request, which normal one-route-per-
  request dispatch does not do. Real smell, not a break. (Will still de-duplicate
  it while fixing travelport.)
- **stays/stuba `xmlToJson` "redeclare" and ferries/kikoto `_kikoto_*` "redeclare"
  → FALSE / safe.** stuba's `xmlToJson` is defined **inside route closures** (one
  route per request → no clash); kikoto's are **`if(!function_exists())`-guarded**.
  Both load clean (verified). Not broken.
- **flights/sabre "payload has no segments" → CONFIRMED true** (I initially saw a
  grep hit, but it matched `AirBookRS` in the *response* parser, not the request;
  reading the request payload confirmed §8 was right here).

### 12.4 Fixes — status

**All 4 confirmed-broken providers fixed (verified: `php -l` + load-tests + route-collision checks + app boots HTTP 200).**

**Fix 1 — flights/pkfare** ✓
- `actions/refund.php`: rewrote from a byte-copy of issue.php into a real refund
  handler registering `flights/pkfare/refund` (was a duplicate `/issue` → collision).
  It records the refund (`payment_status='refunded'`, valid enum) and defers the
  actual airline refund (PKFare OrderRefund desk) + gateway card refund — honestly,
  no fabricated API call, mirroring the platform-wide §8.1(1) reality.
- `actions/cancel.php`: rewrote from a copy of search.php into a real
  `flights/pkfare/cancel` handler doing PKFare `OrderCancel` (same auth as void.php),
  with TLS on and idempotency. Added it to `index.php` (was never included).
- Result: routes creds/search/issue/refund/void/cancel — **one each, no collision.**

**Fix 2 — stays/travelport** ✓
- Removed the debug `die('here')` at `actions/issue.php:314` that aborted the whole
  booking (105 lines of response-parse + PNR-store + DB-confirm now run).
- Fixed the hardcoded `$hotelLocation = 'DXB'` → reads the real city/location code
  from booking_data (every hotel was being sent to Dubai).
- Fixed the malformed amount `Base="USD149"` → numeric `Base="149.00"` (currency was
  being concatenated onto the number, which Travelport rejects).
- Fixed the invalid `booking_status='failed'` (not in the enum → truncated) → keeps
  `pending` with the error in `error_response`.
- `actions/cancel.php`: fixed writing to a non-existent `status`/`cancelled_at`
  column → writes the real `booking_status`/`cancellation_response`; and it now
  VERIFIES the supplier response (2xx + no SOAP fault) before marking cancelled
  (was marking success unconditionally). TLS re-enabled.

**Fix 3 — flights/travelport (cancel)** ✓
- `actions/cancel.php` called 10 helper functions that were **never written**
  (fatal at runtime). Implemented all 10 in `helpers.php`
  (`travelport_call`, `travelport_get_workbench_offer`, `travelport_get_stored_offer`,
  `travelport_build_cancel_offer_payload`, `travelport_is_smartpoint_ndc`,
  `travelport_response_has_error`, `travelport_extract_error_message`,
  `travelport_action_log_start/step/finish`), each `function_exists`-guarded and
  aligned to the exact HTTP pattern the working issue.php uses (Bearer + TVP-PCC-Core
  + accessGroup + Content-Version:11, TLS on). All 12 functions cancel.php calls now
  resolve; module loads clean.
- (The §8 "log_cert redeclare fatal at load" was already corrected in §12.3 as FALSE.)

**Fix 4 — flights/sabre** ✓
- `actions/issue.php` CreatePNR payload had **no flight segments** (only agency +
  customer) and no ticketing → it created no air PNR and issued nothing. Built a real
  `AirBook.OriginDestinationInformation.FlightSegment[]` block from the segments the
  **search already stores** on the booking (`booking_data.segments`: carrier, flight
  number, origin/dest, dates, RBD class) — real data, not placeholders. Added
  `AirPrice` (retain) + `PostProcessing.EndTransaction` to commit the PNR. Fixed the
  hardcoded `NameReference='ABC123'` (collided on multi-pax) → unique per passenger.
- **Honest limit (documented in-code):** this now creates & commits a real air PNR
  (the core break), but Sabre **e-ticket** issuance is a separate `AirTicketRQ`/
  Enhanced Air Ticket call requiring ARC/BSP authority on the PCC — flagged as a
  follow-up, not assumed done.

### 12.5 What was NOT changed, and remaining honesty notes
- These fixes remove the *breakage* (fatals / dead code / no-segments / collisions).
  They do **not** claim a live booking succeeded — no supplier sandbox call was run
  (no test credentials). Verification here = static + syntax + load/route tests.
- The two **systemic** §8.1 gaps (no automated gateway refunds; no post-payment price
  reconciliation) are platform-wide and were **out of scope** for "fix the broken
  providers" — they remain open and are still documented in §8.
- Latent cross-codebase smell found while fixing: several modules write a
  **`void_response`** column that does not exist on `bookings` (sabre/kiwi/amadeus/
  mystifly void.php); those writes silently no-op. I used the real
  `cancellation_response` column in the files I touched, but did not sweep the others
  (out of scope). Worth a follow-up.

---

## 13. Systemic fixes — gateway refunds + post-payment price reconciliation

Addresses the two platform-wide gaps from §8.1: (1) refunds never reversed the
customer's charge; (2) no provider compared the live supplier price to what the
customer paid before booking. Both are now **shared functions** wired per provider.

### 13.1 Shared building blocks (built + verified)
- **`refund_gateway_payment($db, $booking, $amount=null, $reason)`**
  (`app/lib/payment-gateway.php`). Mirrors `verify_gateway_payment()`'s per-gateway
  switch. **Real refund API calls implemented for Paystack** (`POST /refund`, Bearer
  secret `c1`, minor-unit amount) **and Stripe** (`POST /v1/refunds`, secret `c2`,
  resolves PaymentIntent from a `cs_…` session id). Internal wallet/credits and every
  other gateway return **`unsupported`** (honest — caller must not claim a refund
  when no money moved). Returns `refunded | failed | unsupported`. Verified: unknown/
  inactive gateways return `unsupported`, missing reference returns `failed`, no fake
  successes.
- **`reconcilePostPaymentPrice($db, $booking, $supplierTotal, $supplierCurrency, $tolerancePct=2.0)`**
  (`app/lib/functions.php`). Compares the live supplier total to `bookings.price_markup`
  in the same currency. **Within tolerance or supplier cheaper → proceed** (note if
  cheaper); **supplier more expensive beyond 2% → ABORT auto-book, flag the booking
  `pending` with `error_response.review_state='price_mismatch'` + the delta, keep the
  payment.** Currency mismatch or zero/unknown amounts also block. Verified across
  +1% (ok), −10% (ok+note), +10% (block), currency-mismatch (block).

> Design decisions (yours): refunds use one shared fn wired into providers;
> price mismatch = **abort + flag for review, keep payment**; rolled out to a few
> providers first (this section), then the rest.

### 13.2 Wired + verified (representative first wave)
- **flights/duffel** — refund.php now calls `refund_gateway_payment` and only marks
  `refunded` if the gateway refund succeeds (else cancels + flags manual). issue.php
  reconciles the live Duffel offer total before `POST /air/orders`.
- **stays/hotelbeds** — refund.php wired (honest messaging: "refunded via X" vs
  "refund manually"). (Booking already enforces Hotelbeds' own 2% server-side
  tolerance, so the app-side price-check is defense-in-depth there.)
- **stays/ratehawk** — refund.php reverses the customer's card after the real RateHawk
  supplier cancel (refunding RateHawk's `amount_refunded`); issue.php reconciles the
  live prebook `payment_types[0].amount` (which can be +20%) before
  `/order/booking/finish/`.

All syntax-checked; both shared fns load; app boots HTTP 200.

### 13.3 Remaining rollout (same pattern, not yet wired)
- **Refund wiring pending:** flights/amadeus_enterprise, kiwi, mystifly, pkfare*,
  sabre, seeru, tbo, travelport; stays/stuba, hotelston, tbo-holidays, wanderbeds,
  travelport; rail/train. (*pkfare refund was already rewritten in §12 — revisit to
  also call the gateway refund.)
- **No refund.php (needs one, or refund handled via cancel/void):** flights/amadeus,
  cars/cartrawler, cars/mozio, ferries/kikoto.
- **Price-check pending:** the remaining issue.php files that re-price after payment
  (amadeus, amadeus_enterprise, seeru, tbo, stuba, hotelston, wanderbeds, cartrawler,
  mozio, kikoto). Each needs the live-supplier-total variable identified before
  inserting `reconcilePostPaymentPrice` (done individually, not blindly).

### 13.4 Honesty notes
- **No live refund/booking was executed** — Paystack/Stripe refund calls and the
  price-check are verified for logic/syntax/wiring, not against live gateway/supplier
  transactions (needs real keys). The functions **fail safe**: they never report a
  refund that didn't happen.
- **Only Paystack + Stripe have real refund code.** mpesa/flutterwave/paypal/coinsbuy/
  fawaterak/cashfree/wallet/credits return `unsupported` until their refund APIs are
  added — surfaced to the operator, not hidden.
- **Tolerance is 2%** (hardcoded default, overridable per call). Not yet a settings
  field; add one if ops need to tune it.

### 13.5 Rollout completed (this pass) — refund wiring

Gateway refund now wired into EVERY real-API provider that has a refund path.
Each attempts `refund_gateway_payment()` and only marks `payment_status='refunded'`
when the gateway refund actually succeeds; otherwise it cancels and records that the
card refund is a manual step (never a false "refunded").

| Provider | Refund wiring | Notes |
|---|---|---|
| flights/duffel | ✓ | (first wave) |
| flights/amadeus_enterprise | ✓ | replaced its "process gateway refund separately" manual note |
| flights/kiwi | ✓ | also fixed out-of-enum `refund_pending`/`refund_response` → `cancelled` + `cancellation_response` |
| flights/mystifly | ✓ | wired at the confirmed-PTR success path |
| flights/pkfare | ✓ | upgraded the §12 rewrite to call the gateway too |
| flights/seeru | ✓ | also fixed out-of-enum `booking_status='refunded'`; refunds net of Seeru fees |
| flights/tbo | ✓ | stub→ now returns the customer's money via gateway; airline refund still TBO desk |
| flights/travelport | ✓ | after the real GDS refund-commit; fixed out-of-enum `booking_status='refunded'` |
| stays/hotelbeds | ✓ | (first wave) |
| stays/ratehawk | ✓ | (first wave) refunds RateHawk's `amount_refunded` after the supplier cancel |
| stays/stuba | ✓ | |
| stays/hotelston | ✓ | |
| stays/tbo-holidays | ✓ | refunds net of TBO cancellation charge |
| stays/wanderbeds | ✓ | |
| stays/travelport | ✓ | was writing to non-existent columns (refund_amount/refund_status) → now real gateway refund + real columns |
| rail/train | ✓ | wired at the async confirmation point (`rail/train/refundResultData`), not the request point |

**No refund.php (refund flows via cancel/void or n/a):** flights/amadeus (void),
cars/cartrawler, cars/mozio, ferries/kikoto — a dedicated gateway-refund path can be
added to their cancel handlers in a follow-up.

### 13.6 Rollout status — price reconciliation

Wired into providers that re-price/re-fetch a live supplier total before booking:
- **flights/duffel** ✓ (live offer total before `POST /air/orders`)
- **stays/ratehawk** ✓ (live prebook `payment_types[0].amount` before finish)
- **flights/tbo** ✓ (FareQuote `TotalFare` before Book/Ticket)

**Not wired (stated honestly, with the reason — NOT skipped blindly):**
- **stays/hotelbeds** — relies on Hotelbeds' own server-side `tolerance=2.00`, so the
  supplier already rejects out-of-band moves; app-side check is optional here.
- **flights/amadeus, amadeus_enterprise, mystifly, hotelston** — these DO re-price;
  each needs its live-total variable pinned by reading the file (a mismatched field
  would create false blocks), so they're queued for the same treatment as tbo, one
  at a time — deliberately not batch-inserted.
- **flights/kiwi, seeru; stays/stuba, wanderbeds; cars/cartrawler, mozio;
  ferries/kikoto** — these book the STORED price without re-fetching a live total, so
  there is no distinct supplier price to compare at issue time. The right fix for
  these is a revalidate/re-quote step BEFORE booking (a larger change per provider),
  not a same-value comparison. Flagged, not silently ignored.

### 13.7 Honesty (unchanged, reaffirmed)
- Verification is static/syntax/wiring + app-boot (HTTP 200) across all touched files.
  No live gateway refund or supplier booking was executed (needs real keys). The
  refund function fails safe — it never reports money returned that wasn't.
- Real refund API code exists only for **Paystack + Stripe**; all other gateways
  return `unsupported` and the provider surfaces "refund manually" — honest, not fake.

### 13.8 Price-check rollout to the re-pricing providers (follow-up pass)

Wired `reconcilePostPaymentPrice()` into the remaining providers that genuinely
re-price after payment — each with its live-total field pinned by reading the code
(not batch-inserted):
- **flights/amadeus** ✓ — repriced offer `price.grandTotal` (from `flight-offers/pricing`)
  compared before Create Order. Also fixed an out-of-enum `booking_status='failed'`.
- **flights/amadeus_enterprise** ✓ — raw repriced `flightOffers[0].price.grandTotal`
  compared before Create Order.
- **stays/hotelston** ✓ — sum of the LIVE `checkAvailability` room prices compared
  before `bookHotel`.

**CORRECTION (honest):** **flights/mystifly** was listed as a re-pricing provider in
§13.6 — that was WRONG. Reading issue.php shows it **explicitly SKIPS revalidation
after payment** (`STEP 1 — skipped … FSC already revalidated pre-payment`,
issue.php:122), so there is NO live supplier total at issue time to compare. A
same-value check would be a no-op. mystifly therefore belongs in the "books stored
price without re-fetching" group; the real fix for it is to **un-skip the MyFareBox
revalidate** before booking (a larger change), not a price-check. Not wired — stated,
not faked.

**Price-check now wired:** duffel, ratehawk, tbo, amadeus, amadeus_enterprise,
hotelston (6 providers — every real-API provider that re-prices post-payment).
**Still needs a revalidate step first (no live price today):** kiwi, mystifly, seeru,
stuba, wanderbeds, cartrawler, mozio, kikoto.

All verified: `php -l` clean, modules load without fatal, app boots HTTP 200.

### 13.9 Revalidate + price-check for the last 8 providers

These 8 book a stored quote/token/reference without a live post-payment re-price.
Handled by class (verified per file — not batch-inserted):

**Genuinely wired (real revalidate + price-check):**
- **flights/seeru** ✓ — issue.php ALREADY re-validates the fare
  (`POST /flights/booking/fare`, returns price_increased/decreased). Inserted the
  price-check at that existing step using the live `booking.price.grandTotal`; aborts
  + flags beyond tolerance instead of booking the new fare. (Guarded to the real
  paid-booking path.)
- **flights/mystifly** ✓ — issue.php previously SKIPPED revalidation. Un-skipped it:
  now calls `api/v1/Revalidate/Flight` (the same call revalidate.php uses), adopts any
  new FareSourceCode, reads the live
  `RevalidateItinerary.AirItineraryPricingInfo.ItinTotalFare` total, and reconciles
  before Book/Ticket.

**Documented gap (clear in-code TODO, NO fake API call):** kiwi, stuba, wanderbeds,
cartrawler, mozio, kikoto. Each now carries a precise TODO at its book point naming
the exact revalidate call needed and why it isn't wired yet:
- **kiwi** — Kiwi Tequila `check_flights` (invite-only; contract must be confirmed).
- **stuba** — its PREPARE step already returns a live price; needs the exact total
  field in the prepare SOAP response confirmed before reconciling pre-confirm.
- **wanderbeds** — `hotel/avail` re-quote contract to confirm.
- **cartrawler** — module warns re-quoting the stored `<Reference>` fails the quote;
  needs CarTrawler guidance on the correct re-validate call.
- **mozio** — re-run search/refresh for the stored `result_id` (which expires);
  contract to confirm.
- **kikoto** — draft+price created pre-payment in bookingRoutes; a `/prices` re-quote
  at confirm needs the sailings+passenger-refs body rebuilt (varies by AI-trip vs
  ferries path) — wiring blind would false-block every booking.

**Why not force the 6:** each needs a supplier-specific (often gated/undocumented)
re-price contract; a wrong request body or total field would send malformed calls or
false-block live paid bookings — the opposite of the goal. They are documented, not
faked.

### Price-check coverage — final
- **Wired (8):** duffel, ratehawk, tbo, amadeus, amadeus_enterprise, hotelston,
  seeru, mystifly.
- **Documented TODO (6):** kiwi, stuba, wanderbeds, cartrawler, mozio, kikoto —
  need supplier re-price API confirmation.

All verified: `php -l` clean on every touched file; mystifly + seeru modules load
without fatal; app boots HTTP 200. No live supplier call executed (needs real keys);
the price-check fails safe (currency mismatch / zero total → block, never a false OK).

### 13.10 The last 6 price-checks + sabre ticketing (follow-up)

Re-examined each of the 6 remaining providers by reading their actual re-price
capability (not the earlier survey). Result: **2 more genuinely wired**, 1 is
**N/A by business model**, 3 remain **honest gated TODOs**.

**Newly WIRED (real re-price call found in the existing flow):**
- **stays/stuba** ✓ — its PREPARE step (CommitLevel=prepare) already returns the
  live price. Now reads `HotelBooking.TotalSellingPrice` (the same field rooms.php
  uses) and reconciles BEFORE confirm; aborts + flags beyond tolerance.
- **stays/wanderbeds** ✓ — its `hotel/avail` call already returns the live net
  total (`wanderbedsNormalizeSummary()['nettotal']`). Now reconciles that against
  the amount paid BEFORE `hotel/book`.

**N/A by design (not a gap):**
- **cars/mozio** — in hosted_checkout mode Mozio is Merchant of Record: it sets the
  price and the customer pays Mozio directly, so there is no "amount paid to us" to
  reconcile (our earnings are a profit share, not a markup). Documented in-code.

**Remain gated TODO (cannot wire honestly without supplier access):**
- **flights/kiwi** — needs a NEW Kiwi Tequila `check_flights` call; Tequila is
  invite-only and the contract can't be confirmed/tested here.
- **cars/cartrawler** — the module's own code warns that re-quoting the stored
  `<Reference>` at issue time fails the CarTrawler booking; a safe re-price needs
  CarTrawler's guidance.
- **ferries/kikoto** — price is locked at draft creation (pre-payment); a `/prices`
  re-quote at confirm needs a sailings+passenger-refs body that varies by booking
  path and would false-block bookings if rebuilt wrong.

**Price-check coverage now: WIRED (10)** — duffel, ratehawk, tbo, amadeus,
amadeus_enterprise, hotelston, seeru, mystifly, **stuba, wanderbeds**.
**N/A (1):** mozio. **Gated TODO (3):** kiwi, cartrawler, kikoto.

**sabre ticketing** ✓ — `actions/issue.php` now requests e-ticket issuance as part
of the CreatePassengerNameRecordRQ (an orchestrated call): PostProcessing includes
**AirTicketRQ** + EndTransaction, so the PNR is booked, priced, committed AND
ticketed in one request. The response is parsed for ticket number(s) (STEP 8b);
`booking_data.sabre_ticketed` / `sabre_ticket_numbers` record the result. If no
ticket number returns, it's a confirmed PNR awaiting ticketing (not falsely marked
ticketed). Auto-ticketing can be disabled per account via module **c5='noticket'**
(PNR-only) when the PCC isn't ARC/BSP ticket-authorised.
  - Honest limit: ticketing designators (printer LNIATA / commission / FOP) are
    account-specific and may need tuning against a live Sabre PCC; not executed
    against a live account here (no sandbox creds). Verified static + load + boot.

All verified: `php -l` clean; sabre/stuba/wanderbeds modules load without fatal;
app boots HTTP 200.

---

## 14. FINAL COVERAGE STATUS (at-a-glance snapshot)

Consolidated status of all the work in §8–§13, **re-verified against the code and
live DB** on the date of this snapshot (grep of actual `refund_gateway_payment` /
`reconcilePostPaymentPrice` call sites, `php -l`, module load-tests, app boot 200).
This is the single place to read "where does it stand" without walking §8–§13.

> **The one caveat that governs everything below:** all of it is **static +
> structural verification** — `php -l`, load-tests, route/collision checks, app
> boot. **No live or sandbox supplier booking, refund, or ticketing call was ever
> executed** (no supplier keys). "Wired and verified" means the code path is
> correct and loads; it does NOT mean a real transaction has been proven. The
> fastest way to upgrade this to "proven end-to-end" is one or two supplier
> sandboxes (Duffel and Hotelbeds offer free ones).

### 14.1 Classification (live DB `modules.booking_class`)
real = 20 · affiliate = 9 · own = 8 · stub = 4 · unclassified = 1 (`cruises`, a
service-type placeholder). Admin panel badges render from this column (§ Phase 1).

### 14.2 Broken providers — FIXED (§12), verified
sabre, flights/travelport (cancel), stays/travelport (`die`), pkfare (dup files) —
all 4 fixed and load-clean. (§8 corrections: the travelport "redeclare fatal" and
stuba/kikoto "redeclare" were disproven and documented.)

### 14.3 Gateway refunds (§13) — 16 of 16 refund paths wired
**Wired (real gateway reversal, only marks 'refunded' if money moved):**
flights: amadeus_enterprise, duffel, kiwi, mystifly, pkfare, seeru, tbo, travelport ·
stays: hotelbeds, hotelston, ratehawk, stuba, tbo-holidays, travelport, wanderbeds ·
rail: train.
- Real refund API code exists for **Paystack + Stripe**; all other gateways return
  `unsupported` → provider surfaces "refund manually" (honest, never a fake refund).
- **No refund.php (refund via cancel/void or n/a):** flights/amadeus, cars/cartrawler,
  cars/mozio, ferries/kikoto — follow-up.

### 14.4 Post-payment price reconciliation (§13) — 10 wired, 1 N/A, 3 gated
**Wired (10)** — compares live supplier price to amount paid, aborts+flags beyond 2%:
flights: amadeus, amadeus_enterprise, duffel, mystifly, seeru, tbo ·
stays: hotelston, ratehawk, stuba, wanderbeds.
**N/A by business model (1):** cars/mozio (Merchant-of-Record; customer pays Mozio).
**Gated TODO (3)** — need supplier re-price contract we can't confirm/test:
flights/kiwi (Tequila check_flights, invite-only), cars/cartrawler (re-quote breaks
the booking per its own code), ferries/kikoto (price locked pre-payment).

### 14.5 Sabre ticketing (§13.10) — wired
CreatePNR now books+prices+commits+**tickets** in one orchestrated request
(PostProcessing AirTicketRQ + EndTransaction); response parsed for ticket numbers
into `booking_data.sabre_ticketed`. Per-account designators (printer/commission/FOP)
may need tuning against a live PCC; `c5='noticket'` forces PNR-only.

### 14.6 Insurance / AirHelp (§11) — built + HTTP-verified
Full `insurance` service + AirHelp flight-compensation-claim integration, tested live
over HTTP (form → claim booking → status page, CSRF enforced). Safe no-op until a
real AirHelp Partner Token is configured.

### 14.7 Still OPEN (documented, NOT done)
- **The rest of §8's audit findings** beyond the 4 broken + 2 systemic: missing
  idempotency keys (double-book risk), TLS disabled on several suppliers, fabricated
  passenger data, "confirmed" set on some failure paths, etc. — unfixed.
- **3 gated price-checks + kiwi** (§14.4) — need supplier API access.
- **4 providers lack a refund.php** (§14.3).
- **Latent cross-codebase bug:** several modules write a non-existent `void_response`
  column (fixed only in files touched here).
- **No end-to-end live/sandbox test of anything.**

### 14.8 Honest one-line verdict
Real-API providers are **substantially more correct and safer than at the start**
(no broken modules, real refunds, price guards on 10) — but **not "fully implemented
and proven end-to-end."** Getting there needs: the remaining audit fixes, supplier
sandbox credentials to actually book/refund/ticket, and confirmation of the 3–4
gated re-price contracts.

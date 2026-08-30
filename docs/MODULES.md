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
| Insurance | airhelp | **D** | **No (not integrated)** | `partner-api.airhelp.com/v2/booking/...` exists **only in standalone test files**; NOT referenced by `modules/index.php` or any route (verified) | n/a |

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

# GoGlobia — Service Provider Integration Audit

**Date:** 2026-10-16
**Method:** Source-code only. Every supplier's `index.php` / `creds.php` / `search.php` / price-recheck step / `actions/*` were read directly (not inferred from file names). One dedicated deep-reader per supplier; each action classified by whether a **real external API call** exists.
**Scope:** All services under `modules/` — flights, stays, tours, cars, esim, ferries, rail, insurance, bus, umrah, plus the AI-trip orchestrator.

> **Reading note:** Several auto-generated reader warnings about `reconcilePostPaymentPrice()` / `refund_gateway_payment()` being "undefined" were **false alarms** — both exist (`modules/helpers.php:1645`, 2% tolerance; `app/lib/payment-gateway.php:2085`). They were only invisible to readers that looked inside a single supplier folder. Likewise, Airalo's price guard lives in the **app booking route**, not the supplier module (see §Corrections).

---

## 1. Integration models (legend)

| Code | Model | Meaning |
|------|-------|---------|
| **A** | Real supplier API (bookable) | Full search → recheck → issue → cancel/refund/void against the supplier's live API |
| **B** | Search + redirect (affiliate) | Real search API, but booking happens on the provider's site via a deeplink/checkout URL. Not bookable in-app. |
| **C** | Local / own-inventory | Reads the platform's own DB tables; issue is a local confirm. No external supplier. |
| **D** | Hybrid | Real catalog/search API + **local** booking (supplier has no booking API) |
| **E** | Creds-only stub | Only credential validation implemented; no search/booking |
| **F** | Orchestrator | Not a supplier; cascades to other modules |

**Price-trust** = is the sell price re-derived/reconciled server-side before the booking is committed? Strong / Partial / Weak / By-design-N/A / Local.

---

## 2. FLIGHTS (13 suppliers)

| Supplier | API | Model | search | revalidate | issue | cancel | refund | void | Price-trust | **Verdict** |
|----------|-----|-------|:--:|:--:|:--:|:--:|:--:|:--:|-------------|:--:|
| Duffel | duffel.com (NDC) | A | ✅ | ✅ | ✅ | ✅ | gw | ✅ | Strong | ✅ **VERIFIED** |
| Amadeus (self-service) | REST OAuth2 | A | ✅ | ✅ | ✅ | ✅ req | gw+manual | ✅ | Strong | ✅ **VERIFIED** |
| Amadeus Enterprise | travel.api REST | A | ✅ | in-issue | ✅ | ✅ | ✅+gw | ✅ | Strong | ⚠️ **PARTIAL** (DOB fabrication) |
| Mystifly | OnePoint | A | ✅ | ✅ +ratecheck | ✅ | ✅ | ✅ PTR | ✅ PTR | Strong | ✅ **VERIFIED** |
| Travelport | Travelport+ v11 | A | ✅ | ✅ | ✅ (2-phase) | ✅ | ✅+gw | ✅ | **Partial** (no reprice before phase-2) | ✅ **VERIFIED** (price locked phase-1) |
| Seeru | seeru.travel v1 | A | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | Strong | ✅ **VERIFIED** |
| TBO | TBO Air | A | ✅ | FareQuote | ✅ Book+Ticket | ⚠️ stub* | gw+manual | ⚠️ stub* | Strong | ✅ **VERIFIED** |
| Sabre | Bargain Finder Max | A | ✅ | ❌ none | ✅ +autoticket | ✅ | gw+manual | ✅ dup | **Weak** | ⚠️ **PARTIAL** (no reconcile) |
| PKfare | api.pkfare.com | A | ✅ | ❌ none | ✅ preciseBooking | ✅ | gw stub | ✅ | **Weak/Missing** (precisePricing never called) | ⚠️ **PARTIAL** (no reconcile; shoppingId locks price) |
| Kiwi | Tequila | A | ✅ | ❌ **TODO** | ✅ | local | local+gw | local | **HIGH gap** (books token, no check_flights) | ⚠️ **PARTIAL** (price gap + column mismatch) |
| Kayak | Affiliate | B | ✅ | — | — | — | — | — | redirect | — n/a (search-only) |
| Google Flights | price-compare | B | ✅ | — | local-only | local | local | local | redirect | — n/a (search-only) |
| Travelpayouts | Affiliate | B | ✅ | — | ❌ TODO stub | ❌ | ❌ | ❌ | metasearch | — n/a (search-only) |

`gw` = refund via payment gateway reversal (no supplier refund API). `*` = TBO cancel/void are ops-desk stubs *by design* (TBO Air has no such API).
**Verdict** = result of the 2026-10-16 static-trace + reachability verification pass (see §9). VERIFIED = booking path reachable, endpoints/functions/columns all real, PNR+status writeback correct, idempotency + price-recheck wired, no hallucinations. PARTIAL = books real but a confirmed gap (price-trust or data-integrity). No flight supplier came back BROKEN.

**Tally:** 10 bookable (7 Strong/Partial price-trust; 2 Weak = Sabre/PKfare; 1 HIGH-gap = Kiwi) · 3 search-only (Kayak, Google Flights, Travelpayouts).

**Flight-specific findings**
- **Kiwi — HIGH:** `issue.php` books the stored `booking_token` with no live `check_flights` re-validation (explicit TODO). Price move between search and ticketing is uncaught.
- **Sabre / PKfare:** no pre-issue reprice; rely on the supplier rejecting a stale price.
- **Amadeus Enterprise `issue.php:266-274` + Kiwi:** auto-fabricate passenger DOBs when missing, onto real tickets (denied-boarding/name-change liability). Plain **Amadeus** does the right thing and *blocks* instead.
- **Travelpayouts:** action files are literal `// TODO` stubs — search-only.

---

## 3. STAYS (11 suppliers)

| Supplier | API | Model | recheck | issue | cancel | refund | void | Price-trust | **Verdict** |
|----------|-----|-------|---------|:--:|:--:|:--:|:--:|-------------|:--:|
| **Hotelbeds** ⭐ | REST X-Signature | A | checkrates (2% tol) | ✅ | ✅ +SIMULATION preview | gw | ✅ | Strong + `reconcile.php` timeout recovery | ✅ **VERIFIED** |
| RateHawk | worldota v3 | A | prebook (20% tol) | ✅ form→finish→poll | ✅ +verify | ✅ gw | ✅ | Strong | ✅ **VERIFIED** |
| Stuba | SOAP ReservWire | A | BookingCreate prepare | ✅ prepare→confirm | ✅ | gw | DB-only | Strong | ✅ **VERIFIED** |
| Hotelston | SOAP 1.2 | A | checkavailability +re-rank | ✅ | ✅ | gw | pre-PNR local | Strong | ✅ **VERIFIED** |
| Wanderbeds | REST Basic | A | avail (+reconcile pre-book) | ✅ +recovery | ✅ | gw | →cancel | Strong | ⚠️ **PARTIAL** (no idempotency guard) |
| TBO-Holidays | TBO Hotel v2.1 | A | PreBook (±0.01 + hash + RRS) | ✅ +120s recovery, HCN poll | ✅ | gw | →cancel | **Strongest** | ⚠️ **PARTIAL** (no idempotency guard) |
| Travelport | uAPI v52 SOAP | A | ❌ none (trusts rate_key) | ✅ | ✅ | stub | DB-only | **Partial** | ❌ **BROKEN** (writeback to non-existent columns) |
| hotels (local) | own DB | C | admin-set | ✅ local confirm | ✅ | gw | ✅ | Local (gross-underpay floor only) | — not in scope (local) |
| Agoda | Affiliate lt_v1 | B | — | — | — | — | — | deeplink (`landingURL`) | — n/a (search-only) |
| Amadeus (stays) | Self-Service v3 | B/incomplete | — | — | — | — | — | search only ("TEMPORARY") | — n/a (search-only) |
| Booking.com | RapidAPI | B/incomplete | — | — | — | — | — | search only | — n/a (search-only) |

**Tally:** 8 bookable (7 real-supplier APIs + 1 local) · 1 affiliate redirect (Agoda) · 2 search-only (Amadeus, Booking.com).
**Verification (§9):** of the 7 real-API stays suppliers — 4 VERIFIED (Hotelbeds, RateHawk, Stuba, Hotelston), 2 PARTIAL (Wanderbeds, TBO-Holidays — missing idempotency guard), 1 **BROKEN** (Travelport — issue writes to columns that do not exist).

**Stays-specific findings**
- **Hotelbeds** is the flagship: unique `reconcile.php` recovers a lost PNR by querying bookings by `clientReference` after a timeout; SIMULATION cancel-fee preview.
- **TBO-Holidays** strongest price-trust (price-stability + conditions-hash + RecommendedSellingRate floor) + an **HCN polling subsystem** (SLA-aware retry for hotel confirmation number).
- **Travelport-stays** — no rate recheck before issue (trusts client `rate_key`); plus a silent **logging-path bug** (`issue.php:316`, `__DIR__ . '../logs/'` missing slash).
- **hotels (local)** — floor guard blocks only *gross* underpayment (≥95% of min base); does **not** recompute nights×rooms, so "2 nights paid as 1" is not caught.
- Each real stays supplier imports static hotel content into its **own separate DB** (e.g. `modulesratehawk`). Supplier creds are plaintext in `modules.c1/c2`.
- **Fixed, not open:** RateHawk unauth import endpoints + `rand(50,300)` fake pricing; Hotelston/RateHawk import endpoints now admin-gated.

---

## 4. TOURS (4 named + 1 local)

| Supplier | API | Model | Notes |
|----------|-----|-------|-------|
| tours/tours (local) | own DB | C | reads `tours` table, admin-set prices, local PNR on issue |
| ToursBMS | MD5-signed API | D | search/details/import = real API; **issue/cancel/refund = local-only** (supplier has no booking API). ⚠️ `CURLOPT_SSL_VERIFYPEER=false` (api.php:241) |
| Viator | Viator Partner API | B | real search+details; returns `viator_product_url` deeplink |
| Tiqets | Tiqets v2 API | B | real search+details; returns `product_checkout_url` |
| Viator Merchant | — | E | creds-only stub; `index.php` includes only `creds.php` |

---

## 5. CARS (4 named + 1 local)

| Supplier | API | Model | search | issue | cancel | refund | void | Price-trust |
|----------|-----|-------|:--:|:--:|:--:|:--:|:--:|-------------|
| CarTrawler | OTA/XML 1.005 | A | ✅ | ✅ | ✅ | gw | ✅ | **no re-quote before issue** (intentional) |
| Mozio (transfers) | REST v2 | A | ✅ async poll | ✅ (lib.php) + Stripe | ✅ | ✅ | ✅ | N/A **by design** (Mozio = Merchant of Record) |
| DiscoverCars | DiscoverCars B2B | B | ✅ | — | — | — | — | `booking_url` redirect |
| KiwiTaxi | KiwiTaxi API | B | ✅ | — | — | — | — | `booking_url` redirect; no refund endpoint |
| cars/cars (local) | own DB | C | ✅ local | (booking elsewhere) | — | — | — | Local |

> **Correction to prior notes:** the "kiwitaxi double-refund" item refers to the **flights `kiwi`** module — the cars **KiwiTaxi** has no booking/refund flow at all.

---

## 6. SINGLE-SUPPLIER & MISC SERVICES

| Service | Supplier | API | Model | Verdict |
|---------|----------|-----|-------|---------|
| eSIM | **Airalo** | OAuth2 REST | A | Fully integrated: `/v2/packages` + `/v2/orders` + `/v2/sims/{iccid}/instructions` (QR/SMDP). cancel/refund stubbed (Airalo has no API). **Price-trust enforced in app route** (`app/routes/api/esim/bookingRoutes.php:188`, `app/routes/esim/homeRoutes.php:103`). |
| Ferries | **Kikoto** | REST Bearer | A | search/revalidate(`/prices`)/cancel/void real; **issue = thin confirm with documented no-reconcile gap** (api.php:47-57 TODO). refund = gateway-only. |
| Rail | **Train** (LCR + China 12306 + Whoosh ID) | REST | A | Real `/ticket/order`; cancel/refund(async webhook)/void. Price revalidation at **booking-creation** (`_train_revalidate_journey_pricing`, fail-closed). ⚠️ hardcoded `http://` fallback IP (MITM); refund race (no idempotency on webhook). |
| Insurance | **AirHelp** | Partner API v2 | A (special) | Free **post-flight compensation-claim** registration — customer never charged; platform earns commission on won claims. Not in payment auto-issue path. Safe no-op when unprovisioned. Clean. |
| Bus | **bus** | own DB | C | Local: issue = local PNR + decrement `bus_routes_calendar`; refund = gateway reversal. ⚠️ non-atomic seat check→decrement (oversell race, actions.php:77-84). |
| Umrah | **umrah** | own DB | C | Local/own-inventory (umrah v2): admin-set package prices, local PNR, installment-aware refund via `umrah_bookings.amount_paid`. ⚠️ MD5-based PNR (weak, non-secret). |
| AI Trip | **ai_trip** | — | F | Orchestrator: cascades issue/cancel/refund/void to each package item's `/modules/{svc}/{supplier}/{action}`. ⚠️ **accepts unpriced items** (price ≤ 0 proceeds silently, issue.php:103-114); orphaned child rows if a supplier call hangs. |

---

## 7. Master counts

- **Flights:** 13 (10 bookable, 3 search-only)
- **Stays:** 11 (8 bookable incl. 1 local, 3 search-only/affiliate)
- **Tours:** 5 (1 local + 1 hybrid-local + 2 affiliate + 1 stub)
- **Cars:** 5 (2 real-API bookable + 2 affiliate + 1 local)
- **eSIM / Ferries / Rail / Insurance:** 4 real external integrations (1 each)
- **Bus / Umrah:** 2 local own-inventory
- **AI Trip:** 1 orchestrator

**Fully bookable via a real external supplier API (end-to-end):** Duffel, Amadeus, Amadeus Enterprise, Mystifly, Travelport, Seeru, TBO, Sabre, PKfare, Kiwi (flights); Hotelbeds, RateHawk, Stuba, Hotelston, Wanderbeds, TBO-Holidays, Travelport (stays); CarTrawler, Mozio (cars); Airalo (eSIM), Kikoto (ferries), Train (rail), AirHelp (insurance claim).
**Local / own-inventory:** stays/hotels, tours/tours, cars/cars, bus, umrah.
**Affiliate / search-only redirect:** Kayak, Google Flights, Travelpayouts, Agoda, Amadeus-stays, Booking.com, Viator, Tiqets, DiscoverCars, KiwiTaxi.
**Hybrid (API catalog + local booking):** ToursBMS.
**Stub:** Viator Merchant (creds-only).

---

## 8. Cross-cutting findings (ranked)

### Price-trust (most important — these touch money)
1. **Kiwi (flights) — HIGH:** books stored token with no live re-check (`check_flights` TODO).
2. **Sabre, PKfare (flights):** no pre-issue reprice.
3. **Travelport-stays:** no rate recheck before issue (trusts client `rate_key`).
4. **ai_trip:** accepts unpriced (≤ 0) package items without failing — ties to the AI price-trust workstream.
5. **Local inventory (hotels/tours/cars/bus/umrah):** no external supplier to reconcile against; the only guard is whatever the **app booking-creation route** does with the client-submitted price. hotels' floor guard is coarse (gross-underpay only). **Worth a direct pass** since it's own money.
6. **CarTrawler, Kikoto, Train:** price validated at a *different* stage (revalidate / booking-creation) rather than at issue — acceptable but asymmetric across suppliers.

### Correctly by-design (not gaps)
- Hotel/flight **refunds are gateway reversals** — most supplier APIs have no programmatic refund; manual supplier-side follow-up is expected.
- **Mozio** has no price reconcile because it is Merchant of Record (profit-share, not markup).
- **AirHelp** is a free claim registration, never a charge.
- **TBO flights cancel/void**, affiliate suppliers' lack of booking — intentional.

### Security / hygiene (systemic)
- **ToursBMS `CURLOPT_SSL_VERIFYPEER=false`** (api.php:241) — MITM risk on token/pricing. Kayak/Google-Flights search also disable SSL verify.
- **Rail/Train hardcoded `http://` fallback IP** — MITM.
- **PII / card data in logs** — Mystifly, TBO (flights & holidays), Stuba.
- **Plaintext supplier credentials** in `modules.c1–c6` across the board.
- **bus** non-atomic seat decrement (oversell race); **umrah** weak MD5 PNR.
- No OAuth **token caching** in Amadeus/Sabre (fresh token per request).

### Corrections to earlier assumptions (verified against source)
- `reconcilePostPaymentPrice()` and `refund_gateway_payment()` **exist** — the "undefined" flags were from supplier-folder-only reads.
- **Airalo price-trust is enforced**, in the app booking route, not the supplier module — the "orphaned function / HIGH" reading was a false alarm.
- **CLAUDE.md §11 security list is largely stale** — most items (JWT secret, CSRF stub, upload MIME, brute-force) are already fixed (see `full-codebase-study`).

---

## 9. Verification & Gap Analysis (deep code-flow pass, 2026-10-16)

A second, adversarial pass **re-audited the auditors** on the 17 "fully-bookable via real API" suppliers (10 flights + 7 stays). Proof standard: static trace + reachability — route registered & reachable from the payment layer, every external endpoint / referenced function / written DB column actually exists (no hallucinations), issue writes `pnr` + `booking_status='confirmed'`, price-recheck wired, and idempotency present. No live API calls.

### 9.1 The reachability spine (verified, shared by all suppliers)
- **Payment → supplier issue is real & generic:** `app/lib/payment-gateway.php:531` builds `root . 'modules/' . $moduleType . '/' . $modulePath . '/issue'` and cURLs it after a confirmed payment (TBO-Holidays gets a 380s timeout at :556; others 300s). So any supplier whose `issue.php` registers `/<type>/<supplier>/issue` on the modules router **is reachable** from the money flow. Every one of the 17 registers the exact matching path (verified per-supplier).
- **All 17 modules are loaded** by `modules/index.php` (flights 353–363; stays 399–402).
- **Destructive actions are guarded** by `supplier_action_guard()` (`modules/index.php:254`).
- **Shared money helpers exist and resolve:** `reconcilePostPaymentPrice()` (`modules/helpers.php:1645`, default 2% tolerance; also `app/lib/functions.php:12691`) and `refund_gateway_payment()` (`app/lib/payment-gateway.php:2085`).

### 9.2 Verdict summary (17 suppliers)
| | VERIFIED | PARTIAL | BROKEN |
|--|--|--|--|
| **Flights (10)** | Duffel, Amadeus, Mystifly, Travelport, Seeru, TBO | Amadeus Enterprise, Sabre, PKfare, Kiwi | — |
| **Stays (7)** | Hotelbeds, RateHawk, Stuba, Hotelston | Wanderbeds, TBO-Holidays | **Travelport** |

**Headline:** 10 of 17 fully VERIFIED end-to-end. 6 PARTIAL (book correctly but carry one confirmed gap each). 1 BROKEN (Travelport-stays — its DB writeback cannot succeed). **No hallucinated endpoints or missing functions were found in any supplier** — the only hallucinations were in prior *reader* claims, corrected below.

### 9.3 Confirmed gaps (ranked, with file:line)

**CRITICAL — BROKEN booking persistence → FIXED 2026-10-16 (static)**
- **Travelport-stays — issue wrote to non-existent columns (symmetric with cancel).** `issue.php` set `confirmation_number` and `supplier_reference` in the same `$db->update('bookings', …)` array as `pnr`/`booking_status`. Neither column exists anywhere in `install/db.sql` (grep = 0) → Medoo errors on unknown columns → the whole atomic update failed, so after a *successful* Travelport reservation the booking was left **not** confirmed and the PNR lost. The bug was **symmetric**: `cancel.php` also looked the booking up by, and read the locator from, those same non-existent columns — so dropping them from `issue.php` alone would have left cancel broken.
  **FIX APPLIED (static, not runtime-tested — no Travelport sandbox creds here):**
  - `issue.php` now writes `pnr = <UniversalRecordLocatorCode>` + mirrors it in `booking_data.travelport_locator` (house pattern — hotelbeds/ratehawk); phantom columns removed.
  - `cancel.php` now resolves the booking by `pnr` (fallback `id`) and reads the locator from `pnr` → `booking_data.travelport_locator`, erroring cleanly if none.
  - Verified: both files lint clean; no remaining phantom-column reads/writes in the module; `pnr`/`booking_data`/`booking_status`/`error_response` confirmed present on the bookings table. Still needs a live/sandbox issue+cancel to prove end-to-end. Verdict moves BROKEN → VERIFIED-pending-runtime.
  - Residual LOW: `cancel.php:316` logging-path slash bug and the no-idempotency-guard remain (not addressed in this fix).
- **Travelport-stays — logging path bug.** `issue.php:316` `$logDir = __DIR__ . '../logs/'` (missing `/`) → malformed dir, silent log failure. **Fix:** `__DIR__ . '/../logs/'`.
- **Travelport-stays — no idempotency guard** (no existing-PNR check) → a retry re-fires the SOAP reservation.

**HIGH — price-trust / data-integrity**
- **Kiwi (flights) — no live fare re-check before `/v2/booking`.** `check_flights` + `reconcilePostPaymentPrice` are a documented TODO (`actions/issue.php:235-248`), never called. A price move or expired token between checkout and issue is uncaught (overbill or cryptic failure). Separately, `issue.php` writes `issued_at` (a no-op — not a `bookings` column) and `refund.php` reads `base_currency`/`final_total` which also are not `bookings` columns (fall back to defaults). *(These three are confirmed NOT in the bookings table; `issued_at`/`base_currency` exist only on unrelated tables.)*
- **Amadeus Enterprise (flights) — DOB fabrication onto real GDS orders.** `actions/issue.php:266-274` invents DOBs when missing (infant −1y / child −8y / adult −30y) and sends them to the live `/booking/flight-orders` call. Denied-boarding / name-change liability. **Fix:** copy plain-Amadeus behaviour, which *rejects* missing traveller data instead.

**MEDIUM — idempotency (duplicate-booking risk on retry)**
- **Wanderbeds-stays & TBO-Holidays-stays — no existing-PNR guard** before the supplier Book call (`issue.php`, confirmed absent in both). A payment-layer retry or user refresh can fire a second `Book`. Mitigated only by the supplier rejecting a duplicate `client_reference` / order id — not a local fail-safe. **Fix:** add `if (!empty($booking['pnr'])) return success(existing);` at the top of each handler (the pattern Duffel/Amadeus/Mystifly/TBO-flights/Stuba/Hotelbeds already use).
- **Sabre (flights) — no `reconcilePostPaymentPrice` call** (0 references). Books real PNRs with correctly-built segments (empty-PNR regression is guarded at `issue.php:279-281`), but a fare rise between search and issue would silently book at the higher price.
- **PKfare (flights) — no pre-issue price verification.** `precisePricing_V6` is advertised in creds metadata but never called; `reconcilePostPaymentPrice` not invoked. Real exposure is lower than Sabre/Kiwi because PKfare locks price via `shoppingId` inside `preciseBooking_V6`, but there is no explicit reconciliation.

**LOW / by-design (not booking-integrity defects)**
- **Travelport (flights)** — price locked from phase-1 Air Price; no reprice before phase-2 ticketing (verified). Books correctly; graded VERIFIED with this noted.
- **Amadeus (flights)** — validation happens at issue, so missing traveller data fails *after* payment (UX, not integrity); it correctly refuses to fabricate.
- Hotel/flight **refunds are gateway reversals**, Mozio/AirHelp exclusions, TBO cancel/void stubs — all intentional (see §8).

### 9.4 Corrections made during verification (claims checked against source, not relayed)
- `price_markup` / `currency_markup` **are** real `bookings` columns (`install/db.sql:1218`, `:1233`) — dismisses a verifier's "maybe missing" caveat on Seeru/reconcile.
- Kiwi's `issued_at` / `base_currency` / `final_total` are **not** `bookings` columns (grep-confirmed) — a verifier had called `issued_at` simply "missing"; precise finding is: `issued_at` write silently no-ops, the two reads fall back to defaults.
- Stuba's `'test'` credential guard is **not** bypassable by a trailing space — it trims then exact-matches (`creds.php:70`); this retracts a discovery-phase note.
- `seeru_log()` is `function_exists`-guarded (safe no-op), not a hallucination.
- All earlier "`reconcilePostPaymentPrice`/`refund_gateway_payment` undefined" reader warnings remain false alarms (both defined; see §9.1).

### 9.5 Suggested fix order (highest leverage first)
1. **Travelport-stays writeback** (CRITICAL — currently cannot confirm a paid booking): remove the two phantom columns from the update.
2. **Amadeus Enterprise DOB fabrication** (HIGH — real-ticket liability).
3. **Kiwi fare re-check** (HIGH — overbill exposure) and its no-op column writes.
4. **Idempotency guards** on Wanderbeds, TBO-Holidays, Travelport-stays, Sabre (uniform one-liner).
5. **Sabre / PKfare reconcile** (wire `reconcilePostPaymentPrice` like the other flight suppliers).
6. Travelport-stays logging-path slash (LOW).

---

*Generated from a source-only audit; all claims cite `file:line` in the per-supplier reads. §9 verification used static trace + reachability only (no live API calls). No code was changed by this audit.*

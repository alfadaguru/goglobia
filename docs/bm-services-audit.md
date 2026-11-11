# Admin Booking Manager — Per-Service Audit (one at a time)

> **Scope:** audit every service the BM supports, **one at a time**, live + manual,
> including the forms, line by line. No lies, no guessing, no hallucinations, no
> duplication. Every claim carries a `file:line`; anything not determinable from
> code is marked **CAN'T-VERIFY-FROM-CODE** — never asserted as fact.
>
> **Catalogue (source of truth — `bookingManagerRoutes.php:77–108`):** 11 services.
> flights ✅live · hotels/stays ✅live · tours ✅live · cars ✅live · rail ✅live ·
> ferries ✅live · esim ✅live · umrah manual-only · visa manual-only · bus
> manual-only · insurance manual-only.
>
> **Already done before this pass:** flights + hotels (deep audit + fixes; see
> `docs/bm-flight-flow-audit.md` and commits 297aaf5/49f45df/4e2c364/25f310e/
> bd55ed5/5a7851f). This doc covers the remaining 9.

---

## 1. TOURS (live + manual) — AUDITED

### Live path
- **Form** (`manager.php:74–90`): Destination (city autocomplete via `acCity`→`/admin/stays/search-locations`), Activity date (`live.checkin`), Adults, Children. **OK — matches the tours supplier search contract.**
- **Search payload** (`manager.php:1062–1068`): `destination/city/start_date/activity_date/adults/children/infants:0/currency`. **OK.**
- **Normalize/card** (`manager.php:1151–1155`): `label=name`, `sub=location · duration`, price = `display_price||price`. Compact card (tours have no rich-card treatment — acceptable; not a flight/hotel). **OK.**
- **Create handle gate** (`bookingManagerRoutes.php:558–560`): requires `offer_id || tour_id || original_id` in the offer. **OK — rejects a handle-less tour before payment.**
- **Create persists** (`:562–573`): `booking_data=$offerBd` (issue reads `booking_data['booking_data']['offer_id']`) + display fields from `tour_context`. **OK.**

### Supplier matrix (verified by running the guards + reading each issue.php)
| dir | has issue.php | is_stub | BM-live-bookable | nature |
|---|---|---|---|---|
| adivaha | Y | N | **YES** | real external — reads `offer_id/tour_id/original_id`, calls `reconcilePostPaymentPrice` ✅ |
| tours | Y | N | **YES** | **OWN-INVENTORY** — `search.php:305` reads local `tours` table; `issue.php` confirms locally + md5 PNR (no supplier call) — **correct for own inventory** |
| toursbms | Y | N | **YES** | BMS-sourced catalogue (`$mdb products/regions`); `issue.php` (44 L) confirms locally + md5 PNR, **no confirm-back** — see NOTE |
| tiqets | N | — | no | search-only (no issue.php) — correctly gated ✅ |
| viator | N | — | no | search-only — correctly gated ✅ |
| viator_merchant | N | — | no | search-only — correctly gated ✅ |

### Findings
- **NOTE (not a proven bug) — `toursbms` local-confirm:** `modules/tours/toursbms/actions/issue.php:28–37` generates an `md5` PNR + marks `confirmed` with **no supplier API call**, and its own docblock says *"No supplier booking API exists, so this confirms the booking locally"* (`:3–4`). Its `search.php` sources products from an external BMS (`$mdb`), so **IF** the BMS has a booking-confirmation API that fulfillment depends on, issuing locally would be a paid-but-not-confirmed gap (the googleflights class). BUT I **cannot prove from code** that such an API exists — the module asserts it doesn't. So: recorded as **CAN'T-VERIFY-FROM-CODE**, not asserted as a bug. `tours` (own-inventory) is definitively fine. If the owner confirms toursbms needs a confirm-back, the fix mirrors the googleflights denylist pattern.
- **NO other issues.** The affiliate/stub gate correctly blocks tiqets/viator/viator_merchant; the handle gate is present; price-reconcile is present on the one real external supplier (adivaha).

### Manual path
- **Form** (`manager.php:562–573`): tour_name, destination, tour_type, start_date, duration, pickup/meeting point, adults, children, optional confirmation #. 
- **Builder** (`bookingManagerRoutes.php:1053–1064`): reads exactly those fields 1:1 into the voucher `booking_data`. **OK — clean match, no missing/extra fields.**

**TOURS verdict: SOUND.** Live gating correct, handle + reconcile present on the real supplier, manual path clean. One honest CAN'T-VERIFY item (toursbms confirm-back) flagged for owner confirmation, not asserted.

---

## 2. CARS (live + manual) — AUDITED + 1 BUG FIXED

### Live path
- **Form** (`manager.php:91–100`): Pick-up location (text/IATA), Drop-off (blank=same), pick-up/drop-off date+time, Driver age, Driver country (ISO2). **OK — matches mozio/cartrawler search contract.**
- **Search payload** (`manager.php:1069–…`): pickup/dropoff location+date+time, driver_age, driver_country. **OK.**
- **Normalize/card** (`manager.php:1156–1159`): `label=name||car_name`, `sub=transmission · vehicle_type · vendor`, price=`display_price||price`. Compact card. **OK.**
- **Create handle gate** (`bookingManagerRoutes.php:584–588`): requires `reference_id || result_id || search_id || id` — covers cartrawler (`reference_id`) AND mozio (`search_id`). **OK.**
- **Create persists** (`:589–603`): whole card as `car_data` + `search_params` + display keys. **OK.**

### Supplier matrix
| dir | has issue.php | nature | bookable (after fix) |
|---|---|---|---|
| cartrawler | Y | real — inline cURL + `reconcilePostPaymentPrice` (437 L) | **YES** ✅ |
| mozio | Y (dispatcher) | real — `issue.php` delegates to `lib.php` `mozioIssueReservation` (real cURL `POST /v2/reservations/`, poll, idempotent) | **YES** (was wrongly `no` — see BUG) |
| cars, discover_cars, kiwitaxi | N | search-only (no issue.php) | no — correctly gated ✅ |

### ⚠️ BUG FOUND + FIXED — `bm_module_is_issue_stub` false-positive on the dispatcher pattern
- `modules/cars/mozio/issue.php` is a thin **dispatcher** (37 L): it `require`s `lib.php` (`:13`) and calls `mozioIssueReservation($db,$invoiceId)` (`:28`). The REAL booking (cURL to Mozio `/v2/reservations/`, PNR write, already-issued idempotency) lives in `lib.php:266+` — **mozio is a fully real supplier.**
- But the stub detector read ONLY `issue.php`, which has no `curl_`/`'pnr'`/`booking_status` of its own → it mis-flagged mozio as a **stub and BLOCKED it from BM live** (verified: `is_stub=Y` before the fix). This is the inverse of the googleflights issue — the detector was too strict for suppliers that delegate to a lib.
- **FIX** (`booking_manager.php` — in `bm_module_is_issue_stub`): fold in the source of any local PHP file the issue.php `require`s **within the same module dir** (realpath-guarded, never follows arbitrary paths), so "does it book?" is judged on the WHOLE handler. **Verified:** mozio now `bookable=YES`; googleflights + travelpayouts still blocked (no lib require → fake-confirm/TODO detection intact); all real flights/tours/cars suppliers unchanged.
- **Scope check (honest):** swept all services for the same pattern. Only mozio was affected. `flights/travelpayouts` (no require, real TODO stub) stays correctly blocked; `rail/train` never reaches the stub gate (rail uses the separate `rail_create_order` path, returning at `bookingManagerRoutes.php:421` before the gate at `:446`).
- **CAN'T-VERIFY-FROM-CODE:** whether mozio is an enabled cars module on this install — the fix is correct regardless (an enabled real supplier must not be falsely blocked).

### Manual path
- **Form** (`manager.php:575–586`): Vehicle, Transmission, Vehicle type, pick-up loc/date/time, drop-off loc/date/time, Driver age, optional Confirmation #.
- **Builder** (`bookingManagerRoutes.php:1066–1079`): reads those 10 fields 1:1. **OK — clean match.**

**CARS verdict: SOUND after fix.** cartrawler real; mozio real (was wrongly blocked — FIXED); search-only providers correctly gated; manual clean.

---

## 3. RAIL (live + manual) — AUDITED + 1 stale-copy fix

Rail is special: its create path goes through the shared **`rail_create_order()`**
money path and returns **before** the generic stub/handle gate
(`bookingManagerRoutes.php:394→421`, gate is at `:446`). So the stub detector
never applies to rail (confirmed in the Cars audit sweep too).

### Live path
- **Form** (`manager.php:351–396`): From/To station code, travel date, adults, children → `railSearch()` posts to the real supplier endpoint `/ticket/trainQuery` (`:1495`). Train results expand to seat classes; `railSelect` prefills the sell price from the chosen seat (`:1504–1510`). **OK.**
- **Order assembly** `buildRailOrder()` (`:1511–1542`): builds the exact `rail_create_order` input (journey leg + train-format passengers via `buildPassengers`), carrying `price_total_limit` from the chosen seat (which the server then re-validates). **OK.**
- **Create** (`bookingManagerRoutes.php:394–423`): requires `journey` + `passengers` (`:397`), injects contact name/email/phone, delegates to `rail_create_order($db,$input,{sell_price,created_by_admin,booking_channel:'admin_live',grant_session:false,is_agent:false})`, returns here. **No money-logic duplication — one shared audited path.** ✅

### Money-safety of `rail_create_order` (`app/routes/rail/bookingRoutes.php:87+`) — VERIFIED
- **Server-side fare re-validation** (`:101–118`): re-derives the supplier fare via `_train_revalidate_journey_pricing` (fresh `/ticket/trainQuery`), **overwrites the client's `price_total_limit`**, and is **FAIL-CLOSED** — returns a 409 if the fare can't be verified. The client-typed price is never trusted. ✅
- **Sell-price floor** (`:198–199`): `$sellPrice = max($totalPrice, round($opts['sell_price'],2))` — a BM staff sell price can never go **below** the supplier net → no under-charge/loss. ✅
- Markup (`:127`) + promo (idempotent `:252–253`) applied; `price_original=net`, `price_markup=sell` (`:211–212`); `created_by_admin` + `booking_channel='admin_live'` stamped (`:243–244`); `grant_session` honoured (BM passes false → the staff session doesn't own the customer's invoice). Single insert (`:246`). ✅

### Findings
- **FIX (LOW, stale copy) — misleading manual banner:** `manager.php:615` told staff *"Live train search & ticketing … a staff live-rail tool is planned"* — but the live-rail tab already exists (`:351–396`, catalogue `rail=>live:true`). Corrected the banner to point staff to the Live supplier search tab. (Copy-only; no logic change.)
- **NO money/functional issues.** Empty-passenger rail correctly fails-closed with a clear message (`buildPassengers` adds no lead fallback → backend rejects at `:397`) — right behaviour, not a bug.
- **CAN'T-VERIFY-FROM-CODE:** whether a rail module row is enabled (`type=rail,status=1,active=1`) — the live tab is correctly gated on `suppliers.length`, so it only shows when one is enabled.

### Manual path
- **Form** (`manager.php:612–626`): from/to station + code, departs/arrives (datetime-local), seat/class, train #, optional booking reference.
- **Builder** (`bookingManagerRoutes.php:1119+`): reads those fields 1:1 (depart/arrive → `from_date_time`/`to_date_time` via `strtotime`; codes uppercased). **OK.**

**RAIL verdict: SOUND.** The money path is the strongest in the BM (server-verified fare, fail-closed, sell-floor, shared not duplicated). One stale banner fixed.

---

## 4. FERRIES (live + manual) — AUDITED + 1 HIGH defect CONTAINED

### The defect (CONFIRMED against source — paid-but-unissuable)
- **`modules/ferries/kikoto/actions/issue.php:35–39`** reads `$bookingData['reference']` and **HARD-FAILS** ("No Kikoto reference found") when it's absent. kikoto/issue only **CONFIRMS** a pre-existing Kikoto draft (`POST /bookings/{reference}/confirm`, `:58`) — it does NOT create the booking.
- The **reference is minted only by the CUSTOMER submit path**: `app/routes/api/ferries/bookingRoutes.php:675` calls `_kikoto_request('POST','/bookings',…)`, reads `reference` from the response (`:762`), and stores it (`:1057`).
- The **BM ferries create persisted `'reference' => ''`** (`bookingManagerRoutes.php:650`) and **never called `POST /bookings`** — so a BM live ferry had no reference.
- **Chain for a BM live ferry:** create (reference='') → pay → `bm_settle` → `bm_fire_supplier_issue(ferries,kikoto)` → kikoto/issue reads reference='' → **FAILS**. Money collected, nothing issued → stranded. (The create comment at `:624` claiming "kikoto/issue re-prices and books" was **inaccurate** — issue.php's own docblock `:47–57` says there is no re-price/book at that step, only confirm.)
- **Severity:** HIGH (paid-but-unissuable), conditional on ferries being live-enabled (a DB fact — **CAN'T-VERIFY-FROM-CODE**), but the code path was unsafe regardless.

### Fix (honest containment — not a faked booking call)
Wiring the full Kikoto draft-creation (`POST /bookings` with the sailings + passenger-refs body) into the BM is a substantial feature, not a one-liner — so I did NOT fabricate it. Instead:
1. **Catalogue** `ferries => 'live' => false` (`bookingManagerRoutes.php:97`) → `service_live` false (`:164`) → the UI **live tab no longer renders** (`manager.php:57 if($svcLive)`). Ferries is now manual-only, consistent with visa/bus.
2. **Backend belt-and-braces:** the ferries live create branch now `bmAdminJson`-rejects at entry (`:622`) with a clear message routing staff to Manual mode — so even a crafted POST can't create a stranded ferry. The original persist code is kept below the guard (unreached) for when draft-creation is added.
- **Follow-up (owner, when desired):** to restore BM live ferries, replicate the customer draft step — build the `/bookings` body from the chosen sailing + `ferry_passengers`, call Kikoto, store the returned `reference`, then remove the guard + flip `live` back to true.

### Live path (now disabled) — what existed
- Form (`manager.php:400–436`): port dropdowns (loaded via `ferries/kikoto/ports`), date, adults, children; `ferrySearch` → `ferries/kikoto/search`; `ferrySelect` prefills price. The search/UI itself worked — only the **issue** was broken. Supplier gate: kikoto `has=Y, stub=N` (real confirm + `reconcilePostPaymentPrice` present but, per its docblock, not actually re-pricing at issue).

### Manual path (the supported path) — SOUND
- Form (`manager.php:640–648`): departure/arrival port, sailing date, departure time, vehicles, pets, optional reference.
- Builder (`bookingManagerRoutes.php:1171–1188`): reads those 1:1 into the `draft.*` voucher shape the ferries ticket template expects, `confirmed:true`, vehicles/pets as count-placeholder arrays. **OK.**

**FERRIES verdict: manual SOUND; live was a real paid-but-unissuable defect — now CONTAINED (manual-only + backend guard).** Not hidden, not faked — the draft-creation gap is documented for a proper future fix.

---

## 5. eSIM (live + manual) — AUDITED (no change; strongest price-trust)

### Live path — SOUND, and the best-hardened of the live services
- **Form** (`manager.php:101–118`): country picker (datalist backed by the same `airalo_countries` list the storefront uses) + package type (global/local). **OK.**
- **Search payload** (`manager.php:1061`): `country/type/limit:100`. Normalize (`:1146–1149`): `label=title`, `sub=country · data · validity days`. **OK.**
- **Create** (`bookingManagerRoutes.php:611–628`): persists ONLY `airalo_order.package_id` (the handle) + display fields — **does NOT persist a client price.** Rejects a package with no id (`:618`). **OK.**
- **INSERT identity** (`:777–778`, `:490`): live eSIM → `module_type='esim'`, `module='airalo'` (`$moduleName=$supplier`). This **satisfies airalo/issue's `$isWebEsim` gate** (`issue.php:38`) so a BM eSIM is accepted + provisioned — NOT rejected the way GIA `ai_trip` eSIMs once were. ✅ (verified the gate at `issue.php:38–43`.)
- **Supplier gate:** airalo `has=Y, stub=N → bookable`. `airalo/issue.php` (301 L) is a REAL provisioning call (`POST /v2/orders`) with:
  - **Idempotency** (existing-provision guard `:49`) + **concurrency claim** on `issue_claimed_at` (`:71–81`, stops two concurrent orders).
  - **PRICE-TRUST GUARD** (`:109–158`): re-fetches the **authoritative** Airalo price server-side (`airalo_authoritative_price`), is **quantity-aware** (the F-02 qty-bypass fix — `price_markup` is whole-order, authoritative is per-single-SIM), and runs `reconcilePostPaymentPrice` → **HOLDS** the eSIM for review if the live price rose above paid. So a mistyped/manipulated BM sell price **can't** cause an under-paid provision. ✅ **This is the only live service that re-prices the exact handle at issue AND is quantity-aware.**

### Manual path — SOUND (credential-guarded, defense in depth)
- **Form** (`manager.php:650–662`): package name/country/data/validity + **ICCID** and **Activation reference** (both marked required `*`, red-border when both empty, explanatory note).
- **Guard (both layers):** client (`manager.php:1598`) AND server (`bookingManagerRoutes.php:680–685`) reject a manual eSIM with no ICCID **and** no reference — an eSIM can't be fabricated. **Fail-closed, defense in depth.** ✅
- **Builder** (`bookingManagerRoutes.php:1190–1205`): reads those fields 1:1 into an `airalo_provision`-shaped voucher (`reference→order_id`, `iccid→sims[].iccid`) so the ticket shows the REAL credential. **OK.**

**eSIM verdict: SOUND — no change needed.** Best price-trust of all live BM services (server-side authoritative re-price + quantity-aware reconcile + idempotency + concurrency claim). Manual path credential-guarded on both layers. The module-identity wiring correctly makes a BM eSIM an `$isWebEsim` booking that airalo/issue accepts.

---

_(Next: Umrah — manual-only.)_

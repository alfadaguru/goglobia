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

_(Next: Rail.)_

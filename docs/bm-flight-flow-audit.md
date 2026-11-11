# Admin Booking Manager — FLIGHTS (Manual + Live): Complete Flow Audit

> **Scope (owner order):** audit the admin Booking Manager → Flights, **both Manual and Live**, across **UI, UX, backend, and DB** — every source file on the flow read **line by line**. Includes all 14 live flight suppliers' `search.php` + `issue.php`.
>
> **Standard:** no rush, no lies, no false reporting, no hallucinating, no skipping. Every claim carries a `file:line`. Anything not determinable from code (DB values, live behaviour) is marked **UNPROVABLE-FROM-CODE** — never assumed.
>
> **Finding tags:** `GAP` (missing/weak), `BUG` (verifiably wrong), `UX` (usability), `NOTE` (observation / by-design), `UNPROVABLE` (needs live run/DB).

**Files on the flow (verified surface):**
| # | File | Lines | Role |
|---|---|---|---|
| route | `app/routes/admin/bookingManagerRoutes.php` | 1191 | console + /search /read-ticket /create /pay /verify /retry-issue |
| UI | `app/views/admin/booking-manager/manager.php` | 1303 | Alpine console (manual form, live search, pax, AI reader, pay/verify) |
| UI | `app/views/admin/booking-manager/verify.php` | 129 | verification queue |
| lib | `app/lib/booking_manager.php` | 574 | settle, notify, issue loopback, guards |
| seam | `modules/helpers.php` | — | supplier_action_guard, supplier_internal_token |
| live | `modules/flights/<14 suppliers>/search.php` + `actions/issue.php` | ~10k | live search + issue |
| db | `install/db.sql` | — | bookings + gia_booking_payments + self-healing cols |

**Progress:** 🟡 P1 route (in progress) · ⬜ P2 UI · ⬜ P3 lib+verify · ⬜ P4 DB+seam · ⬜ P5 14 suppliers · ⬜ P6 artifact.

---

## P1 — `app/routes/admin/bookingManagerRoutes.php` (1191 lines)

### 1.1 Helpers (`:22–126`)
- `bmAdminJson()` (`:22–29`): JSON + `exit`. Sets 200 or passed code. **OK.**
- `bmAdminCsrfOk()` (`:30–36`): reads `$_POST['csrf_token']` OR `X-CSRF-TOKEN` header → `CSRF::validateToken`. Returns **false if the `CSRF` class is absent** (`:34`) → fail-closed. **OK.**
- `bmGenInvoiceId()` (`:37–47`): 8-hex id, 6 collision retries (`:41–44`), then a **12-hex fallback that does NOT re-check collision** (`:45`). — `NOTE (very low): the fallback return skips the has() check; collision probability on 12 hex bytes is negligible, but it is an unchecked write.`
- `bmCompanyBankAccount()` (`:48–65`): first `bank_transfer` gateway with `status='1'` (`:52`), else any `bank_transfer` (`:53`); maps `c1..c5`→account/routing/number/address/iban (`:56–60`). — `NOTE: the account shown to customers depends entirely on a correctly-configured bank_transfer gateway row; no validation that c1..c5 are non-empty.` — `UNPROVABLE-FROM-CODE whether the row is configured on this install.`
- `bm_service_catalogue()` (`:77–108`): flights `live=true` (`:80`). Single source of truth. **OK.**
- `bm_service_to_type()` (`:110–118`): unknown service → `'flights'` (`:117`, safe default). `bm_service_is_valid()` (`:120–126`). **OK.**

### 1.2 Console render — `$bmRenderManager` (`:133–188`)
- Auth: `ADMIN_AUTH()` (`:134`) + `admin_require($db,'bookings','view')` (`:135`). **OK — gated.**
- Invalid service → coerced to `'flights'` (`:136`). **OK.**
- Suppliers for LIVE: `modules` where `type=$serviceType AND status=1 AND active=1` (`:140`), de-duped lowercased names (`:141`). **OK — data-driven.**
- Senior flag + company bank only for seniors (`:143–144`). **OK (least-privilege).**
- **Recent bookings (`:147–150`): `['module_type'=>$serviceType, 'booking_channel[~]'=>'admin', ...]`.** — ⚠️ **VERIFY (Medoo `[~]` LIKE):** per the known Medoo tilde bug (needle with `_` not auto-wrapped), but here the NEEDLE is `'admin'` (no underscore) and the stored values are `admin_live`/`admin_manual`. Medoo `[~]` wraps `%admin%` → matches `admin_live`. **Needs confirming the needle wraps as `%admin%`** — flagged `UNPROVABLE-FROM-CODE-ALONE` until the Medoo LIKE behaviour for a plain needle is re-confirmed (the memory bug is about needles that CONTAIN `_`, not this case). _Pending verification in P4._
- Base currency from `currencies` default (`:164–167`). eSIM country list (`:173–179`, not flight path). Renders header + `manager.php` + footer (`:185–187`). **OK.**

### 1.3 Route registration (`:190–198`)
- Explicit per-service GET routes (`:193–195`) to avoid shadowing `/search`, `/verify` (`:191–192` comment). Bare `/booking-manager` → flights (`:198`). **OK — the shadowing concern is real and correctly handled.**

### 1.4 `POST /booking-manager/search` — live supplier search proxy (`:205–242`)
- Auth `ADMIN_AUTH` + `admin_require(bookings,view)` + CSRF (`:206–208`). **OK.**
- Requires `supplier` (`:212`); validates it's a real enabled module with a `search.php` on disk (`:215–217`). **OK — can't proxy an arbitrary path.**
- Forwards `$_POST` (minus csrf/service/supplier, `:220–221`) to `root/modules/<type>/<supplier>/search` via server curl (`:222–233`).
- `NOTE: CURLOPT_SSL_VERIFYPEER=false / VERIFYHOST=0 (:231–232)` — internal loopback to own `root`; URL is server-controlled, not user input → not a vuln as written (consistent with the issue loopback).
- `GAP (low): the search requires the server to curl its OWN public URL (:222). If root is misconfigured (there is a documented root-bootstrap bug in project history) or the host can't loopback, every search fails with an opaque "Supplier search did not respond" 502 (:237).`
- `GAP (low): the raw supplier response is echoed through (:240) with no check that it is valid JSON — a supplier error HTML page reaches the console as unparseable, which the UI must handle.`

### 1.5 `POST /booking-manager/read-ticket` — AI ticket reader (manual prefill) (`:252–276`)
- Auth `ADMIN_AUTH` + `admin_require(bookings,add)` + CSRF (`:253–255`). **OK.**
- File presence validated (`:257–260`); extractor loaded on demand (`:262–268`); returns extracted JSON or a 422 with reason (`:269–275`). **No booking created here (correct).**
- **OK — clean.** (The extractor lib itself is audited where it's reached; this route is a thin, guarded wrapper.)

### 1.6 `POST /booking-manager/create` — build & persist booking (`:288–725`)

**Common header (`:288–379`):**
- Auth `ADMIN_AUTH` + `admin_require(bookings,add)` + CSRF (`:289–291`). **OK.**
- `mode` forced to `manual` for non-live services (`:300–302`); flights are live-capable. **OK.**
- **Idempotency:** `client_token` → `transaction_id='BMCT-'.$token`; a repeat returns the existing invoice (`:307–311`). **OK — double-submit safe.**
- Customer first/last/email required + email-validated (`:319–324`); separate `ticket_email` (traveller) validated, falls back to contact (`:329–333`). **OK — good booker-vs-traveller split.**
- **Pricing (`:335–340`):** `sellPrice` = `$_POST['price']`, `costPrice` = `$_POST['cost_price']` (defaults to sell); requires `sellPrice>0`.
  - `GAP (MEDIUM): for a LIVE flight the sell price is HAND-TYPED by staff (:338), NOT derived from the chosen supplier offer. A mistyped/under-priced live booking is created with no create-time check that the price matches the selected offer; it is only (maybe) caught by the issue-time price reconcile (supplier net vs price_markup). An over-type overcharges the customer with no guard at all.`
- **Passengers (`:342–379`):** parsed into `adult_N/child_N/infant_N`, each requires first+last name (`:368–370`); passport/DOB/nationality are **optional** (`:364–366`, only added `if (!empty(...))`); lead-passenger fallback (`:374–378`); `primary_guest` added (`:379`).
  - `GAP (MEDIUM): passport_number / DOB are OPTIONAL at create even for a LIVE INTERNATIONAL flight. Many supplier fares require passport at issue; omitting it creates a bookable draft that will FAIL at the supplier issue step (after payment) — surfaced only then, as a stranded paid-but-unissued booking. No create-time "international needs passport" gate.`

**LIVE flight branch (`:423–451`):**
- Requires `supplier` + non-empty `offer_booking_data` (`:426–431`). **OK.**
- `bm_module_has_issue_endpoint` (`:432–434`) — supplier must have an issue endpoint. **OK.**
- `bm_module_is_issue_stub` (`:439–441`) — rejects affiliate/search-only suppliers (prevents paid-but-never-issued). **OK (prior fix).**
- `bm_flight_offer_has_handle($offerBd)` (`:446–448`) — flights must carry a usable booking handle. **OK (prior fix).**
- Persists `['flight_data'=>$offerBd,'booking_data'=>$offerBd,'admin_created'=>true]` (`:451`). **OK.**
- `NOTE: the live branch does not re-validate the offer is still bookable at the supplier (offers expire). A stale offer selected, then paid via manual-transfer hours later, can fail at issue. This is the same timing risk flagged in the earlier flight audit (fares expire); handled only by the issue-time flow + stranded-issue recovery, not at create.`

**MANUAL branch (`:606–635`):**
- eSIM/insurance credential guards (`:616–631`, not flight). Flight manual → `module='manual'`, `booking_channel='admin_manual'`, `booking_data = bmBuildManualBookingData(...)` (`:632–634`, audited in P3). **OK.**

**`booked_date` (`:646–652`):** parses datetime-local / datetime, falls back to now (prior fix). **OK.**

**The `bookings` INSERT (`:664–704`):**
- `booking_status` = `confirmed` (manual) / `pending` (live) (`:667`); `payment_status='unpaid'` (`:668`). **OK.**
- `price_original=$costPrice`, `price_markup=$sellPrice` (`:669–670`). `email=$recipientEmail` (`:675`). `user_id=null` (`:690`). `pnr`= typed (manual) / `''` (live) (`:696`). `created_by_admin=$adminId` (`:700`). `transaction_id='BMCT-'+token` dedup key (`:689`). **OK.**
- `FINDING — GAP (LOW): tax=0 / tax_type='fixed' hardcoded (:671–672).` BM bookings never carry a tax line. The `:335` comment calls the sell price "all-in", so tax is presumably baked in — `NOTE this is likely by-design, but the invoice shows no tax breakdown and any jurisdiction that itemises tax is unsupported here.`
- `FINDING — GAP (LOW): country / address / nationality stored EMPTY (:678–679, :692).` Fine for a manual voucher; a **live international** supplier issue may require them → another contributor to issue-time failure.
- `FINDING — GAP (LOW): $currency is only uppercased (:337), never validated against the currencies table.` A typo'd/invalid currency code is persisted on the booking and its money fields.
- Response returns invoice + mode + amount (`:706–715`). **OK.**

### 1.7 `POST /booking-manager/pay` (`:726–813`)
- Auth `ADMIN_AUTH` + `admin_require(bookings,edit)` + CSRF (`:727–729`). Already-paid short-circuit (`:735–737`). **OK.**
- **Tender mutual-exclusion (`:741–758`):** refuses a 2nd tender while `awaiting_verification` (`:749–751`); refuses manual_transfer if a DVA is pending/paid (`:752–757`). **OK — real money-safety (prevents double-pay).**
- `dva` (`:760–769`): `gia_bp_create_dva(..,'admin',..)`; 503 if unavailable, 422 on fail. **OK.**
- `manual_transfer` (`:771–799`): sets `awaiting_verification`, fires `bm_notify_pending_verification` (prior fix, present). **OK.**
- `mark_paid_offline` (`:801–810`): manual-only guard (`:804`) → settle `'offline'`. **OK.**
- `FINDING — UX GAP (LOW): when no bank_transfer gateway is configured, bmCompanyBankAccount returns null (:775) so company_bank is null (:793), yet a senior still gets the message "Give the customer the company account below" (:795–796).` The message promises bank details the response does not contain.

### 1.8 `POST /booking-manager/verify` (`:820–845`)
- Auth + CSRF + **senior-only** (`:821–824`). Already-paid no-op (`:830–832`); must be `awaiting_verification` (`:833–835`). **OK — correct state guards.**
- Settles `bm_settle_booking_payment(..,'manual_verified',..)` with `amount=price_markup` (`:837–843`). **OK structurally.**
- `FINDING — GAP (MEDIUM, money): verify marks the booking PAID IN FULL at price_markup (:841) with NO check that the amount actually transferred matches.` The senior clicks Verify and it's fully paid regardless of what landed; `transaction_id` is optional (`:827`); there is no partial-payment / amount-reconciliation path. A short transfer is recorded as full payment.

### 1.9 `POST /booking-manager/retry-issue` (`:852–888`)
- Auth + CSRF + senior-only (`:853–856`). Must be `paid` (`:861–863`); already-has-PNR no-op (`:864–866`). `refund_if_fail` opt-in (`:875`). Settles via issue-only recovery (`:876–880`). **OK — sound recovery path.**

### 1.10 `GET /booking-manager/verify` — verification queue (`:893–920`)
- Auth + `admin_require(bookings,view)`; queues only for seniors (`:894–900`).
- Pending = `payment_status='awaiting_verification'` (`:899`). **OK.**
- **Stranded = `payment_status='paid' AND booking_status[!]='confirmed' AND booking_channel[~]='admin_live'` (`:908–913`).**
- `FINDING — BUG CANDIDATE (HIGH): the stranded query filters booking_channel[~]='admin_live' (:911). The needle 'admin_live' CONTAINS an underscore.` Per the project's documented Medoo bug ([Medoo [~] underscore bug] — Medoo does NOT auto-wrap `%..%` when the needle contains `_`/other special chars), this LIKE may be sent as `LIKE 'admin_live'` (exact-ish, `_` = single-char wildcard) **instead of `LIKE '%admin_live%'`**, so it can **match nothing** → the stranded ops queue would be **empty even when paid-but-unissued live bookings exist**, hiding money-collected-nothing-bought bookings from the senior. **This is the same bug class the memory says hid ALL supplier reservations.** → **MUST confirm in P4** whether this exact call wraps; if it doesn't, it's a real HIGH (ops-blindness to stranded money). _Not asserted as confirmed — flagged for P4 verification._

### 1.11 `bmBuildManualBookingData` — FLIGHT manual voucher (`:925–1172`, flight branch `:1111–1172`)
- Parses `segments` JSON → per-segment from/to/datetimes/airline/code/flight#/class/baggage (`:1116–1132`). Builds `passengers` excluding `primary_guest` (`:1138–1147`). Builds `flight_data` incl. trip-level `departure_code`/`arrival_code` + `routes[0].segments` (`:1149–1169`). **OK.**
- `FINDING — GAP (LOW, data integrity): no validation that manual flight segments exist/are ordered/from≠to (:1116–1132).` A manual flight submitted with zero valid segments yields empty `routes`/`flight_data` → a **blank ticket**. (The console should enforce ≥1 segment — verified in P2.)
- `NOTE: departure_date = date('Y-m-d', strtotime($first['departure_datetime'])) (:1163) — a malformed typed datetime silently becomes ''.`

### 1.12 `bmStoreManualDocument` (`:1175–1191`)
- `is_uploaded_file` (`:1179`), **extension-only** allow-list pdf/png/jpg/jpeg/webp (`:1180–1182`), 8 MB cap (`:1183`), random name (`:1186`), `move_uploaded_file` (`:1188`).
- `FINDING — NOTE (LOW): extension-only validation, no MIME/content check (:1180–1182)` — inconsistent with `handleFileUpload` (which checks MIME). Low risk (admin-gated, non-`.php` extension, stored under uploads/), but a mislabeled file passes.

**P1 COMPLETE — entire 1191-line route read line-by-line.** Route findings: 2 MEDIUM (hand-typed live price; passport optional for live intl), 1 MEDIUM money (verify = full-paid regardless of amount), **1 HIGH candidate (stranded-queue Medoo `[~]` — pending P4 confirm)**, several LOW (currency unvalidated, tax=0, empty country/addr, search loopback opacity, blank-segment manual, extension-only doc, misleading no-bank message). Clean: all auth+CSRF, idempotency, tender exclusion, live stub/handle guards, verify/retry state guards.

## P2 — `app/views/admin/booking-manager/manager.php` (1303 lines) — console UI/UX

### 2.1 Header / picker / mode toggle (`:1–60`)
- Reads `$bm` globals, `$csrf` session, escapes `$svcLabel` (`:7–8,30`). Service picker with "manual" badges (`:41–49`). **OK.**
- `FINDING — UX (LOW): the "Live supplier search" button is x-show="suppliers.length" (:58).` If no flight suppliers are enabled, the live button **silently vanishes** — staff get no "live unavailable, enable a supplier" hint; it looks like live mode doesn't exist.

### 2.2 Live flight search form (`:121–188`)
- Trip type, From/To airport autocomplete, depart/return, adults/children, cabin (`:122–160`). Search button w/ spinner (`:184–188`). **Live-fare-expiry amber warning present (`:191–196`, prior fix). OK.**
- `FINDING — GAP (LOW): live flight search has adults + children only, NO infants field (:156–157)` — yet the backend + ticket handle `infant_N`. An infant booking can't be priced via live search.
- `NOTE: offer cards show the supplier name (:205) — ADMIN/staff-facing console, so NOT a customer supplier leak (consistent with the supplier-hiding mandate, which is customer-facing only).`

### 2.3 Manual flight form (`:340–443`)
- Booked-date field (`:342–348`, prior fix). AI ticket reader, flights only (`:350–373`, prior feature). Segment grid: airline autocomplete + flight#/code + from/to autocomplete + departs/arrives + cabin + baggage + add/delete segment + PNR (`:380–443`). **OK — comprehensive.**
- `FINDING — GAP (LOW, confirms P1 :1116): the UI guarantees ≥1 segment ROW (delete hidden when length==1, :438) but does NOT require the row to be FILLED` — an all-blank segment still submits, and neither UI nor backend requires a non-empty from/to → a blank-flight ticket is possible.

### 2.4 Customer + passengers (`:585–643`)
- Customer first/last/email/phone + separate ticket_email (`:585–597`). **OK.**
- Passenger grid: adult/child add (`:611–612`), **"International trip" checkbox** (`:606–610`), passport shown only `x-if="isInternational"` (`:625–627`), optional e-ticket number (`:634–637`).
- `FINDING — GAP (MEDIUM, confirmed in UI): passport is (a) gated behind a MANUAL "International trip" checkbox (:608) that nothing auto-sets from the actual route, and (b) even when shown, is optional — label says "recommended" (:616), the input has NO required (:626).` → a **LIVE international flight** can be created and paid with **no passport**, then **fail at the supplier issue** after payment. No route-based auto-detect of international.
- `FINDING — GAP (LOW): passenger grid has no "Add infant" (:611–612)` despite backend `infant_N` support.

### 2.5 Price + pay (`:645–699`)
- Sell / net-cost / currency (`:649–651`) — **confirms P1: sell price is HAND-TYPED for both manual AND live (:649).** (Whether live `price.sell` is auto-filled from the selected offer is in the JS — §2.6.)
- Pay buttons: DVA / manual transfer / offline (offline only for manual, `:674`). DVA + company-bank result blocks (`:680–699`).
- `NOTE: the company-account block is x-if="companyBank" (:690) so it correctly hides when no bank gateway is configured — but the /pay toast still says "give the customer the account below" (P1 :795). UI-vs-toast mismatch confirmed.`

### 2.6 Alpine `bookingMgr()` component (`:731–1302`)

**`runSearch()` (`:887–955`):** validates From/To/depart (`:899–902`), requires suppliers (`:903`), builds flight params (`:936–941`), **fans out to ALL enabled suppliers in parallel, merges + sorts by price** (`:945–952`). One supplier failing is swallowed (`:951`). **OK — good parallel fan-out.**
- `FINDING — GAP (LOW): flight search hardcodes infants:0 (:940)` — no infant pricing (consistent with §2.2).
- `FINDING — GAP (LOW): per-supplier errors are swallowed (:951)` — "no offers" is indistinguishable from "every supplier errored"; no staff diagnostics.

**`normalize()` (`:956–993`):** flight offer reads `it.segments[0][0]` or `it`, price `it.price||seg.price` (`:989–990`). `NOTE: assumes the segments[0][0] shape; a supplier returning a different offer shape yields a blank/0-price card (per-supplier shapes verified in P5).`

**`selectOffer()` (`:994–999`) — CORRECTION to P1 §1.6:**
- `this.price.sell = Number(r.price||seg.price||r.display_price||0) || this.price.sell` (`:997`) + currency (`:998`).
- **CORRECTION: the live sell price IS auto-filled from the selected offer (:997), it is NOT purely hand-typed as P1 first stated.** The accurate finding: `GAP (LOW, was MED): the price field stays EDITABLE after auto-fill (:649), and the ||this.price.sell fallback (:997) keeps any previously-typed value when the offer price is 0/missing. So the normal live path prices correctly; the residual risk is a staff edit or a 0-price offer creating a mispriced live booking — narrower than "hand-typed".` _(The earlier MEDIUM is downgraded; no false reporting — the create route does accept whatever price is in the field, but the UI auto-fills it.)_

**`createBooking()` (`:1185–1285`):**
- Validates name/email (`:1186`), `price.sell>0` (`:1187`), live offer selected (`:1190`). **OK.**
- `FINDING — GAP (MEDIUM, confirmed at submit): createBooking does NOT require passport for an international live flight (:1185–1193)` — no client-side gate; combined with §2.4 (optional passport field) and P1 §1.6, a live international flight can be created + paid with no passport → supplier issue fails after payment. **This is the strongest, most consistent finding across P1+P2.**
- Live flight payload: `offer_booking_data = seg.booking_data || r.booking_data || r` (`:1252–1254`) — carries the handle (backend-validated by `bm_flight_offer_has_handle`). Manual: clean segment strip (`:1258–1262`). Idempotency token re-minted on success (`:1280`). **OK.**
- `NOTE (UX): after one create the form locks (created flag, :653 :disabled); a second booking needs the Reset link (full reload). Acceptable, but no inline "new booking".`

**`pay()` (`:1290–1300`):** posts method; DVA / manual_transfer handled (`:1293–1297`). `manual_transfer` keeps old `companyBank` if response null (`:1296`). **Confirms the company-account UX mismatch (block hidden when null, but backend message still promises it).**

**P2 COMPLETE — manager.php read (header, forms, passenger grid, price/pay, all key JS methods).** UI findings: 1 MEDIUM (no passport gate for international live, confirmed at UI + submit), several LOW (no infants, swallowed search errors, blank-segment, hidden live button, company-account message mismatch). **Corrected P1's live-price finding (auto-filled, not hand-typed).**

_(Next: P3 — booking_manager.php lib + verify.php, line-by-line.)_

---

## FINDINGS LEDGER (accumulates; severity-ranked at the end)
_(none finalized yet — audit in progress)_

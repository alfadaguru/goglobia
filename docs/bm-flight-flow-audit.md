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

_(continues: manual flight branch + bmBuildManualBookingData + the bookings INSERT — reading next.)_

---

## FINDINGS LEDGER (accumulates; severity-ranked at the end)
_(none finalized yet — audit in progress)_

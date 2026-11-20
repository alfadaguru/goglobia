# Umrah module — deep 3-angle audit (customer / agent / admin)

**Date:** 2026-11-20  **Method:** source-only, line-by-line. Every claim carries a
`file:line` the auditor personally read. No memory/guessing. Clobber-safe reads.

**Scope / universe (verified on disk):**
- Engine (`app/lib/umrah/`, 3,370 LOC, all loaded at `config.php:205-208`):
  `services.php` 1485 (price/hold/booking/settle/installments) · `groups.php` 800
  (agent B2B group engine) · `operations.php` 497 (travellers/docs/notify/readiness)
  · `admin_crud.php` 421 · `listing.php` 167.
- Doc-token helpers `app/lib/functions.php:66-81`; public serve `ajaxRoutes.php:786`.
- Settlement integration `payment-gateway.php` (6 umrah branches) + `adyen.php:384`
  + GIA `ai/gia_booking_payment.php:609` + wallet/credits gateway views + `api/umrah/v1Routes.php`.
- Customer routes `app/routes/umrah/umrahV2Routes.php` 417 + `bookingRoutes.php` 244.
- Admin routes `app/routes/admin/umrahV2Routes.php` 1237.
- GIA `ai/gia_umrah.php` 665 + `ai/tools/umrahTools.php` 274.
- 27 `umrah_*` tables.

---

## P1 — ENGINE (money + concurrency + security spine). STATUS: read in full.

### Verified STRONG (no change needed) — the money spine is the best-hardened in the repo

| Area | Where | Verified behaviour |
|---|---|---|
| Server is sole price authority | `services.php:5-6,114,235,938` | Browser only carries a `quote_ref`; price **re-resolved from the live departure_tier at hold, booking AND settle**. Quote is NOT a price authority (H1). |
| Bookable gate (single choke-point) | `services.php:21-35` | Rejects non-`published` OR `archived=1` departure AND draft/hidden/sold_out tier. Reused by quote/hold/booking/group-confirm/cart (BUG-1 fix present). |
| No double-markup | `services.php:101-102,189-195` | Direct sell price used verbatim; MARKUP only in the inert `cost_plus_markup` fallback. |
| Agent B2B promo window | `services.php:128-135` | B2B promo only within `promo_start/end`, else `b2b_net`, else B2C (audit M2). |
| Customer pax cap | `services.php:218,928` | `UMRAH_CUSTOMER_MAX_PAX=5` enforced at quote AND re-enforced at booking commit (agents exempt). |
| Hold concurrency | `services.php:446-519` | `$db->action` + `SELECT … FOR UPDATE` on tier **and** explicit parent-departure row lock → cross-tier contention serialized on one lock; departure-level cap inside tx (H4/MED). Last-seat race closed. |
| Quote/hold binding | `services.php:904-911` | booking rejects tier-mismatch AND pax≠qty mismatch (audit H: charge-1-reserve-5). |
| Re-check bookable at commit | `services.php:918` | departure can't be unpublished between hold and booking. |
| Settlement idempotency | `services.php:1151-1194` | FOR UPDATE on `umrah_bookings`; real-txn fast-path dedupe; **empty-txn synthesized key + `$seen` check INSIDE the lock** (U-05) so two concurrent empty-txn callbacks can't double-credit. |
| Partial-payment lossless split | `services.php:1219-1250` | Under-amount shrinks the pending slice + inserts a matching `paid` micro-row → SUM(amount) unchanged (balance correct), SUM(paid) rises (money credited). Old code silently discarded the remainder (M3). |
| Tri-state → generic enum | `services.php:1290-1298` | umrah deposit/partial maps to generic `partially_paid` (audit #5), not a blanket `paid`. |
| Confirm-on-qualify + hold consume | `services.php:1264-1282` | `paid ≥ first installment` → booking confirmed, `price_locked_at` set, hold `held→consumed` (guarded on state='held'). |
| Abandonment sweeps | `services.php:541-627` | hold expire-sweep + **booking** expire-sweep (H5: held+unpaid+amount_paid=0+grace → cancelled, frees seats, reflects on generic row). |
| Installment reminders | `services.php:647-…` | reminder-once (stamps `reminder_sent_at`), flips past-due pending→overdue; NULL-due_at (100% collapse) correctly skipped. |
| Agent group confirm (money) | `groups.php:405-640` | CAS claim (no double-charge) → pre-charge capacity → wallet charge → in-tx `FOR UPDATE` re-check (U-03 oversell) → materialize; **full charged-amount reversal** on any failure (audit H under-refund); agent_earning from live B2C−net. |
| Group visa auto-refund | `groups.php:652-762` | admin-only (`:654`); per-member idempotency-keyed `wallet_refund`; **refund-reversal (claw-back) on re-approve** (L-02); proportional fee share. |
| Doc upload hardening | `operations.php:109-171` | MIME via finfo (not ext), PHP-injection head scan, size cap, `.htaccess` Deny-all, random filename, per-booking path. |
| Public doc token | `functions.php:66-81` + `ajaxRoutes.php:786-816` | HMAC-SHA256 over `docId|exp` with install-specific `supplier_internal_secret()` (`.env` JWT_SECRET or sha256(db+pw) — not a shipped constant); expiry-checked; `hash_equals`; file path from DB not user input (no traversal). Forged/expired/bad-sig → 403. |
| Generic auto-refund exclusion | `payment-gateway.php:2928` | umrah excluded from `_auto_refund_on_issue_failure` (correct: own-inventory, no supplier-issue step) BUT `refund_gateway_payment:2994-2998` IS umrah-aware (part-payment clamp reads `umrah_bookings.amount_paid`, never over-refunds mid-installment — audit H2). |

### P1 CANDIDATE FINDINGS (to confirm against the route layer in P3/P4)

- **U-ENG-1 (to verify, MED):** `umrah_group_set_status` (`groups.php:771-798`) lets an
  **admin** set a group to `cancelled` from ANY status (`:777` valid-list includes
  `cancelled`; `:796` writes unconditionally) with **NO wallet refund**. A paid/processing
  group cancelled this way leaves the agent charged. Refund only happens through the
  **visa** path (`set_visa`) or group-confirm rollback. → In P4 confirm whether the admin
  UI exposes this transition on a paid group, and whether a separate admin refund exists.
- **U-ENG-2 (to verify, journey gap not a bug):** there is **NO** `umrah_refund` /
  `umrah_booking_cancel` engine fn and **NO single-booking cancel/refund handler in the
  admin umrah route** (1237 lines — only the group-visa auto-refund). A single B2C pilgrim
  who paid a deposit and wants out can only **flag** `cancellation_request=1`
  (`bookingRoutes.php:205`, no refund, no inventory release); staff must refund via the
  **generic** admin bookings page (`refund_gateway_payment`, which is umrah-aware). → P4:
  confirm the generic path works for umrah + releases the seat; flag the missing in-panel button.
- **E-02 (documented, latent):** empty-txn settlement AUTO-key is not re-fire-safe
  (`services.php:1176-1189`) — all real callers pass a stable ref, branch logs loudly if
  reintroduced. Accepted-as-is by prior audit; re-confirm all 6 call sites pass a txn in P3.

---

## P2 — CUSTOMER angle (web routes + /api/v1/umrah/* + views). STATUS: read in full. VERDICT: CLEAN.

Customer universe read: `umrahV2Routes.php` 417 (all GET display + customize POST),
`api/umrah/v1Routes.php` 823 (the money + mutation endpoints), `invoiceRoutes.php` 191
(pay page), `bookingRoutes.php` 244 (post-booking), views `invoice/index.php` 801 +
`v2/{confirmation,checkout}.php`.

### Customer journey, end to end (verified)
1. **Browse** `/umrah` → `$umrahV2Results` → `umrahV2PublishedDepartures` (published+active
   only). Price always via `umrah_price_resolve` (server). No client price anywhere.
2. **Detail** `/umrah/packages/{slug}` — `?departure=` validated against THIS template's
   published departures, foreign id falls back to first (`umrahV2Routes.php:119-125`).
3. **Checkout** `/umrah/checkout` (GET, display) → `POST /api/v1/umrah/checkout`
   (`v1Routes.php:344`): CSRF-guarded; pax cap re-enforced (`:358-362`); **pilgrims array
   count MUST equal pax** (`:367`); every pilgrim's identity validated **before any money**
   (`:371-381`); pax_type derived **server-side** by A/C/I position, client value ignored
   (`:412-420`, audit MED manifest-integrity); quote→hold→book via the hardened engine;
   guest invoice ownership granted (`:458`, customer-flow #3).
4. **Pay** `GET /invoice/umrah/{id}` (`invoiceRoutes.php:7`): `enforceInvoiceAccess` IDOR
   (`:22`); **passport gate** backstops a direct-API checkout that skipped uploads
   (`:33-53`); expiry; `(float)…+(float)('' tax)` TypeError fixed (`:96`); callback →
   `handle_payment_callback` → `umrah_settle_payment`. **Pay amount = next pending
   installment**, server-computed (gateway views `wallet_balance.php:217`,
   `credits.php:224`, `payment-gateway.php:1927`, `v1Routes.php:811`) — deposit-plan
   customer charged only the deposit slice.
5. **Complete pilgrim details post-booking** on confirmation page → two endpoints, both
   **ownership-gated** via `umrah_v1_can_manage_booking` (admin|owner|guest-allow-list):
   `POST …/bookings/{ref}/travellers` (`:739`) and `POST …/travellers/{id}/documents`
   (`:750-753` resolves traveller→booking→gate, so no upload to a foreign traveller id).
6. **Cancellation request** → flag only (see U-ENG-2), CSRF+IDOR gated (`bookingRoutes.php:170,193`).

### Verified STRONG (customer)
- **Price-trust:** nothing customer-facing trusts a client price; re-resolved at quote/hold/
  book/settle. Pay amount server-derived from the installment ledger.
- **IDOR:** every read/mutate gated — confirmation view (`umrahV2Routes.php:319-332`),
  invoice (`enforceInvoiceAccess`), v1 mutations (`umrah_v1_can_manage_booking`),
  doc-upload resolves ownership through the parent booking.
- **CSRF:** `umrah_v1_csrf_guard` on every state-mutating v1 endpoint (bearer-exempt,
  cookie clients must carry token); customize POST CSRF-guarded.
- **Passport-extract AI:** CSRF + per-session rate-limit (10/15min) + MIME/size/dimension
  validation (`v1Routes.php:250-293`).
- **XSS:** invoice view traveller names + special_requests `htmlspecialchars` (`index.php:697,711`);
  avatar initials are single-char `substr`. Only raw echo is `$booking['pnr']` (admin-set
  ticket PNR, not customer input) and date-formatted fields.
- **Guest flow:** guest can view+pay+complete their own booking via session allow-list +
  `grantInvoiceSessionOwnership`; cannot reach anyone else's.
- **Waitlist:** input-validated, dedup, length-capped (`v1Routes.php:762-795`).

### P2 OBSERVATIONS (not bugs)
- **O-P2-1 (by design):** children & infants are charged the **same unit price as adults**
  (`umrah_price_quote` = unit×pax, no A/C/I discount). Correct for umrah (each pilgrim = a
  seat + visa). Confirm the checkout UI doesn't *promise* a child discount → P2-view note:
  checkout collects A/C/I purely for the manifest, price is per-pilgrim-flat. No mismatch found.
- **O-P2-2:** customer self-service cancellation is request-only (U-ENG-2) — intentional for
  a high-value installment product, but the UI should make "staff will process your refund"
  explicit. → carried to P4/P5 as a UX recommendation, not a code bug.

**P2 RESULT: no code defects. The customer money + IDOR + CSRF + XSS surface is clean.**

---

## P3 — AGENT angle (dedicated group flow + GIA chat). STATUS: read in full. VERDICT: CLEAN.

Umrah agents do NOT use the generic agent booking-centre — they use a **dedicated group
flow** (`umrah_groups`) + GIA chat. Both route through the same hardened engine.

### Agent group flow (web `umrahV2Routes.php:360-391` + API `v1Routes.php:597-716` + engine `groups.php`)
- **Horizontal IDOR fully closed:** every group mutation (`set_counts`, `add_member`,
  `drop_member`, `submit`, `confirm`, `set_status`, passport-upload) resolves via
  `umrah_group_owned($db,$gid)` (`groups.php:119-128` → `agent_user_id===actor` OR admin).
  Member-update re-checks the member∈group (`:185`). An agent cannot touch another agent's group.
- **Price server-authoritative:** `umrah_group_recount` (`:49-74`) re-resolves the agent-net
  unit from the live tier (B2B net when set, else B2C) and recomputes total — never a client total.
- **Roster frozen post-submit** (`:160`, audit H total-desync) — a paid group's total can't be
  edited out from under the booking.
- **Money moves ONLY at `/confirm`** (wallet debit) — the P1-verified `umrah_group_confirm`:
  CAS claim (no double-charge) + capacity pre-check + in-tx FOR UPDATE re-check (U-03) +
  full charged-amount reversal on failure. Submit (`/submit`) moves NO money.
- **All API endpoints CSRF-guarded** (`umrah_v1_csrf_guard` on every POST).
- **Agent earning** = (B2C − net) × pax from the live tier (`groups.php:474-477`) — anti-tamper.
- **Visa auto-refund** (`umrah_group_set_visa`, admin-only `:654`): per-member idempotency
  keys + claw-back on re-approve (L-02) + proportional fee share. (P1-verified.)

### GIA chat (`ai/gia_umrah.php` 665 + `ai/tools/umrahTools.php` 274 + `ai/gia_booking_payment.php`)
- **No chat-supplied price ever reaches money.** `gia_umrah_quote` is an "honest wrapper over
  the shared `umrah_price_quote`" (`gia_umrah.php:483,499`); `gia_umrah_book` (`:519-615`)
  runs `umrah_price_quote → umrah_hold_create → umrah_booking_create` — the identical engine
  the web uses. Price re-resolved at commit (anti-tamper).
- **Payment plan never invented** (`:545-546` — falls back to a real active plan).
- **Price-drift re-confirm (D7-1, `:579-595`):** if the tier price moved between the quoted
  figure GIA showed and commit, a `price_changed` flag + the quoted total are surfaced so chat
  re-confirms the new amount instead of implying the stale one. Not a block (booking real+held).
- **umrah_book tool is R2** (held, UNPAID — `umrahTools.php:189`); passports collected before pay.
- **GIA settle path (`gia_booking_payment.php:606-658`)** passes the **real Paystack ref** to
  `umrah_settle_payment` (`:610`) — satisfies E-02 stable-txn. Stamps gateway/txn on the generic
  row for refund-ability (`:602-617`). **Under-payment handling (excellent):** umrah's partial
  settle leaves the booking held/partially_paid (seat unsecured); this flags ops + nudges the
  customer with the exact balance needed, gated on `status==='settled'` (no webhook-retry spam).

**P3 RESULT: no code defects. Agent group IDOR, price-trust, money-movement ordering,
and GIA engine-reuse are all clean. The only agent-side money refunds are idempotent + spine-traced.**

---

## P4 — ADMIN angle (admin umrahV2Routes 1237, ~45 routes + admin views). STATUS: read in full.

### Verified STRONG (admin)
- **Auth is consistent and correct on EVERY route:** `ADMIN_AUTH()` + `admin_require($db,
  <area>,<action>)` + `CSRF::guard()` + a second `umrahV2AdminCsrfOk()` on every mutation.
  Area/action granularity is right: departures/media/templates/tiers/plans → `umrah` area
  (view/add/edit/delete); bookings/operations/groups → `bookings` area.
- **Money-permission split is deliberate:** the visa-outcome route requires `bookings.refund`
  (not `edit`) precisely because it moves money (`umrahV2Routes.php:1035`), closing the
  "any edit employee can refund" gap.
- **No admin-view XSS:** bookings/groups/operations views escape all traveller/member PII.
- **CSV exports formula-injection-safe:** `$csvSafe` prefixes `'` on `=+-@\t\r` leads
  (`:700,:1145`).
- **Document serve (H6)** `admin/umrah-manager/document/{id}` is `bookings.view`-gated.
- **Group visa auto-refund** (admin): idempotent, spine-traced, claw-back on re-approve. (P1.)

### 🔴 U-ENG-2 — HIGH (CONFIRMED functional+money bug): a single (non-group) umrah booking cannot be cancelled or refunded by ANY admin UI; its inventory hold also leaks.
**Root cause (verified at line):**
- A single umrah booking writes `module_type='umrah', module='umrah'` (`services.php:1012-13`).
- The admin umrah route (1237 lines) has **NO** single-booking cancel/refund handler — only
  the group-visa path.
- The **generic** admin bookings quick-actions resolve the per-module action file:
  `cancel_booking` → `modules/umrah/umrah/actions/cancel.php` (`bookingsRoutes.php:630`),
  `refund_request` → `modules/umrah/umrah/actions/refund.php` (`:665`).
  **`modules/umrah/` does not exist** (verified: no such directory). → both return
  `{"status":false,"message":"Cancel file not found" / "Refund file not found"}`.
- The booking-**edit** form's flip-to-cancelled branch (`bookingsRoutes.php:250-288`) sends a
  notice and frees inventory **only for stays/hotels/tours/cars** — umrah is in neither list,
  so **no refund AND no umrah hold release**; the comment even says "Money refund is the
  separate refund action" (`:263`) — which is the broken one above.
- Confirmed system-wide: `refund_gateway_payment` is wired to visa/cruise/rail/GIA/cron/
  auto-refund — **never** umrah. No code releases an `umrah_inventory_holds` row on a generic
  cancel.

**Impact:** a paid single B2C umrah customer who asks to cancel (they can only set
`cancellation_request=1`) **cannot be refunded through any admin screen**, and if staff flip
the booking to "cancelled" via the edit form, the seat's inventory hold stays `consumed`
forever (phantom capacity loss). The refund *capability* exists (`refund_gateway_payment` is
already umrah-aware via the part-payment clamp, P1) — it is simply **never triggerable** for umrah.

**Fix options (pick one):**
1. Add `modules/umrah/umrah/actions/cancel.php` + `refund.php` that call `refund_gateway_payment`
   (card/bank) OR `wallet_refund` (wallet/credits), then set the umrah booking cancelled,
   release its hold (`umrah_hold_release`/mark hold released), and reflect on the generic row —
   mirroring the group path's idempotent, spine-traced reversal; OR
2. Add a dedicated `admin/umrah-manager/bookings/cancel` + `/refund` route (gated
   `bookings.cancel` / `bookings.refund`) doing the same, and add umrah to the edit-form
   inventory-release branch (`bookingsRoutes.php:266`).

### 🟠 U-ENG-1 — MEDIUM (CONFIRMED): admin can cancel a PAID group with only `bookings.edit` and no refund.
**Verified:** `admin/umrah-manager/groups/status` (`:993-1002`) is gated `bookings.edit`
(not `refund`) and passes an arbitrary `status` (incl. `cancelled`) to
`umrah_group_set_status`, which for an admin writes the status **unconditionally with no
wallet refund** (`groups.php:777,796`). So a paid/processing group set to `cancelled` here
leaves the agent's wallet charged and the seats' hold unresolved. Contrast the visa route
which correctly requires `bookings.refund` *because* it moves money.
**Fix:** either (a) block `cancelled` on a paid/processing group through this route (require
the money to be reversed via a refund-gated path first), or (b) have
`umrah_group_set_status` refund the agent (idempotent, like `umrah_group_confirm`'s rollback)
when transitioning a paid group to cancelled, and require `bookings.refund` for that transition.

**P4 RESULT: admin CRUD/auth/CSRF/XSS/export surface is CLEAN; TWO money-lifecycle holes
(U-ENG-2 HIGH, U-ENG-1 MED) in the cancel/refund path are the only real defects found in the
whole module.**

---

## SUMMARY — whole-module verdict

The umrah **engine, customer, and agent/GIA** surfaces are the best-hardened in this codebase:
server-only pricing, atomic holds with departure-level locks, idempotent settlement with
in-lock empty-txn dedupe and lossless partial-split, full IDOR/CSRF/XSS coverage, and
spine-traced idempotent agent refunds. **0 defects in P1/P2/P3.**

The **only** real defects were on the **admin money-lifecycle (refund/cancel)** path — **both now FIXED + live-tested:**
- **U-ENG-2 (HIGH) — FIXED:** added `umrah_admin_cancel_refund()` (`services.php`) — the single
  idempotent, spine-traced handler that reverses only what was collected (wallet→`wallet_refund`,
  card/bank→`refund_gateway_payment`), cancels the umrah + generic rows, and **releases the
  inventory hold** (the seat leak). Wired into all three admin entry points: quick
  `cancel_booking` + `refund_request` (`bookingsRoutes.php`) and the edit-form flip-to-cancelled
  branch. Live test: 18/18 assertions (wallet credited exactly, both rows cancelled+refunded,
  hold released, idempotent no-double-refund).
- **U-ENG-1 (MED) — FIXED:** `umrah_group_set_status()` now (a) rejects a paid-group cancel
  unless the actor holds `bookings.refund` (route passes `can_refund` via `admin_can`), and
  (b) refunds the agent's remaining (total − refunded_total) to the wallet (idempotent),
  cancels the materialized booking, and releases its hold. Live test: permission gate rejects
  without refund right; with it, agent refunded exactly, group+booking cancelled, hold released,
  idempotent.

Both fixes verified against the real DB; all test rows cleaned up (0 left); `/umrah` 200,
admin 302-login — no regression. Branch commit below.

Observations (not bugs): O-P2-1 (A/C/I flat pricing — correct for umrah), O-P2-2 (customer
self-cancel is request-only — intentional; make the "staff will process refund" UX explicit).

# Umrah Redesign — Phase 1 Build Plan

**Basis:** `GoGlobia_Umrah_Service_Full_Engineering_Spec.md` (the requirements)
+ the verified rebuild audit (current module = 15,506 LoC, flat-package model,
~90% net-new for the spec). This plan is Phase 1 only — the spec's "Revenue
Release" (§77): *start selling Standard Economy safely, admin-managed.*

**Guiding decision (from the audit):** build the Umrah **domain** from scratch;
**reuse the shared platform** (routing, `MARKUP()`, payment gateway + loopback,
wallet, cron, admin UI patterns, module id 35). Do **not** bolt departures/tiers/
installments onto the flat `umrah` table.

> Honest scope note: Phase 1 is a multi-session project. This plan sequences it
> into shippable steps, each verified (php -l + boot + DB round-trip) before the
> next. No live supplier booking is in scope (Umrah is own-inventory/manual).

---

## 0. Phase 1 scope (what "done" means)

**In:** Standard Economy tier only; the 6 Oct–Dec departures; direct promo
pricing (₦2,490,000 / was ₦2,800,000) with **no double markup**; server quote;
atomic inventory hold; full payment **and** the 50/25/25 installment / price-lock
plan with dynamic 75/25 & 100% late rules; booking confirmation + reference;
minimal customer trip dashboard (trip card + payment progress + "complete
travellers later"); admin: create/clone/bulk-create departures, set price/promo/
capacity, publish/close, see bookings + receivables; correct routing + legacy
redirect; idempotent payment confirmation.

**Out (Phase 2/3):** full traveller/passport/document/visa/ticketing/rooming
workflows; VIP–VVVVIP tiers; addons; agent umrah flow; waitlist automation;
advanced reports; notifications beyond booking confirmation.

**Acceptance = the spec's §76 items that fall in the In-scope list above.**

---

## 1. Data model (new `umrah_*` tables — idempotent, self-healing)

Follow the codebase pattern: an `ensureUmrahSchema($db)` in
`app/lib/functions.php` called from `config.php` (like `ensureAgentApiSchema`),
**and** the same DDL in `install/db.sql`. All `CREATE TABLE IF NOT EXISTS`, real
`AUTO_INCREMENT` (fix the current `MAX(id)+1` smell).

Phase-1 tables (subset of spec Part N — only what Phase 1 needs):

| Table | Purpose (Phase 1 columns) |
|---|---|
| `umrah_package_templates` | id, code, slug, name, season, marketing_duration, madinah_nights, makkah_nights, itinerary_order(JSON), inclusions(JSON), rooming_note, meta_*, status |
| `umrah_tiers` | id, code, name, public_label, sort_order, default_occupancy, room_sharing, bookable, status *(seed Standard bookable; VIP..VVVVIP as not-bookable placeholders)* |
| `umrah_departures` | id, template_id, code, departure_date, return_date, month_bucket, origin_city, booking_close_at, status(draft/published/closed), capacity, low_stock_threshold, display_inventory_count |
| `umrah_departure_tiers` | id, departure_id, tier_id, regular_price, promo_price, promo_start, promo_end, promo_active, currency, tier_capacity, booking_mode(instant/quote), status |
| `umrah_inventory_holds` | id, departure_tier_id, quote_id, session_ref, user_id, qty, state(held/consumed/expired/released), expires_at, created_at |
| `umrah_quotes` | id, quote_ref, departure_tier_id, tier, pax, unit_price, total_price, amount_due_now, promo_snapshot(JSON), policy_version, currency, expires_at |
| `umrah_bookings` | id, booking_ref (GGU-XXXXXXXX), invoice_id (links to generic `bookings`), user_id, departure_id, departure_tier_id, pax, currency, total_price, amount_paid, balance, price_locked_at, booking_status, payment_status, **snapshot(JSON)** (unit price/promo/inclusions/rooming/plan/terms per §57), created_at |
| `umrah_installments` | id, umrah_booking_id, seq, percent, amount, due_at, status(pending/paid/overdue), paid_at, transaction_id |
| `umrah_payment_plans` | id, code, name, deposit_percent, second_percent, final_percent, second_due_days_before, final_due_days_before, grace_hours, price_lock_on_cleared_deposit, active *(seed PP-50-25-25 + PP-FULL)* |
| `umrah_audit_log` | id, actor, role, entity, entity_id, action, old(JSON), new(JSON), reason, ip, created_at |

**Reuse, do NOT duplicate:** the generic `bookings` + `transactions` tables
still hold the payment/invoice of record; `umrah_bookings.invoice_id` links to
`bookings.invoice_id`. `umrah_settings` (existing) is kept for umrah types/media
lookups. `MARKUP()` stays the pricing engine but is **bypassed** for direct sell
price (see §3).

**Migration stance (decide before coding):** the 2 rows in the legacy `umrah`
table + `umrah_settings` are kept as-is; the new model runs **alongside** the old
until cutover. Phase 1 does **not** auto-migrate the 2 legacy packages (they are
generic packages, not Standard-Economy departures). The old `/umrah/detail/*`
routes get a redirect to the new package/departure (§6).

---

## 2. Backend services (plain PHP helpers, in `app/lib/umrah/` or functions.php)

Small, testable functions (mirroring the agent-API helper style):

1. **`umrah_price_quote($db, $departureTierId, $pax)`** — resolves price by the
   spec §8.2 precedence (departure-tier promo → regular → tier/package direct →
   cost+markup → global fallback). Returns unit/total/amount-due-now +
   promo_snapshot. **Applies MARKUP only in the cost+markup branch** — direct
   sell price is used verbatim (fixes §8.3 double-markup risk). Writes a
   `umrah_quotes` row with expiry (default 20 min).
2. **`umrah_hold_create($db, $quoteId)`** — atomic: `SELECT ... FOR UPDATE` on
   `umrah_departure_tiers` capacity, check `MIN(remaining)` (§20 formula, Phase 1
   = departure capacity − confirmed − active-holds), insert `umrah_inventory_holds`
   with `expires_at`. Returns hold or a "no capacity" result. **Never decrement
   on browser state.**
3. **`umrah_payment_schedule($db, $planCode, $total, $departureDate, $now)`** —
   computes the dynamic 50/25/25 vs 75/25 vs 100% by days-to-departure (§16).
   Returns installment rows (amount + due_at).
4. **`umrah_booking_create($db, $quoteId, $holdId, $lead, $planCode)`** — validates
   quote/hold not expired, creates the generic `bookings` row (`module_type=umrah`,
   reusing existing invoice flow) **and** the `umrah_bookings` row with the §57
   snapshot + `umrah_installments`. Booking starts `held/unpaid`.
5. **`umrah_settle_payment($db, $invoiceId, $amount, $currency)`** — called from the
   payment confirmation path (see §5). Idempotent (keyed on invoice+txn). Marks the
   installment paid, recomputes balance, and **on a cleared qualifying payment**:
   consume the hold → `umrah_inventory_holds.state=consumed`, set
   `umrah_bookings.price_locked_at`, `booking_status=confirmed`. Writes audit.
6. **`umrah_hold_expire_sweep($db)`** — cron: expire holds past `expires_at`,
   return capacity. (New cron route under `app/routes/crons/`.)
7. **`umrah_audit($db, ...)`** — one-liner used by all mutating ops.

All money math in base currency; display conversion via existing
`convertCurrencyAmount()`.

---

## 3. Pricing correctness (the spec's non-negotiables)

- **Direct sell price wins.** `umrah_departure_tiers.promo_price` (₦2,490,000) is
  the customer price as-is. `MARKUP()` is **not** applied on top (audit confirmed
  the old flow would double-apply). Tax stays disabled (seed) and, if enabled,
  is disclosed in the all-in total.
- **Server is the only price authority.** The browser never sets the payable
  amount; it references a `quote_ref`, and the server recomputes/validates at
  hold, booking and payment (§Step 2/7). Reuses the anti-tamper stance already
  proven in the flights booking path.
- **Snapshot at confirmation** (§57): `umrah_bookings.snapshot` freezes unit
  price/promo/inclusions/rooming/plan/terms so later admin edits can't rewrite a
  confirmed contract.

---

## 4. Customer flow (web + `/api/v1/umrah/*`)

**Routes** (register in `_routes.php`; public web under `app/routes/umrah/`, API
under `app/routes/api/umrah/` using a `v1` sub-path per spec §58):

Web:
- `GET /umrah` — new landing: hero (₦2.49M, months, save ₦310k), departure month
  cards, tiers (Standard bookable; others "Request Quote"), inclusions, itinerary,
  payment-plan explainer, FAQ, support. **Mobile-first, CTA in first screen.**
- `GET /umrah/packages/{slug}` (+ `?departure={id}`) — stable detail page
  (replaces fragile `/umrah/detail/(6 parts)`); departure selector updates
  price/availability without breaking.
- `GET /umrah/booking/{ref}` — confirmation / booking view.
- Legacy `GET /umrah/detail/(.*)` → 301/redirect to nearest valid package.

API (`/api/v1/umrah/`): `GET departures`, `GET packages/{slug}`,
`GET departures/{id}`, `POST quotes`, `POST holds`, `POST bookings`,
`POST bookings/{id}/payments`, `GET bookings/{id}`. (Traveller/document endpoints
are Phase 2.)

**Wizard (spec §14):** select departure+tier+pax → server quote → hold (20 min,
server expiry) → lead contact only → payment choice (full / plan) → review+terms
→ pay → confirmation. **Do not collect passports before first payment** (§Step 4
friction rule).

**Minimal dashboard (Phase 1):** trip card (ref, departure, tier, status,
countdown), payment progress (paid/balance/next-due, Pay Now), and a "Complete
traveller details" CTA that is *enabled but points to the Phase-2 flow* (clearly
labelled "coming soon" or a basic collect form — decision at build time).

---

## 5. Payment integration (reuse the existing gateway, add installment awareness)

- Reuse `process_payment()` / `handle_payment_callback()` and the configured
  gateways. **Wallet** payment (agent) also works via the existing path.
- The tricky bit: the current gateway loopback fires
  `modules/umrah/umrah/issue` on *full* payment. For **installments**, Phase 1
  must confirm the booking on the **cleared qualifying deposit**, not only on
  100%. Approach: route umrah payment confirmation through
  `umrah_settle_payment()` (idempotent) which decides "qualifying payment cleared
  → confirm + price-lock" per the plan, independent of the 100% assumption.
- **Idempotency + amount/currency match** enforced in `umrah_settle_payment`
  (spec §7/§59). Duplicate webhook cannot double-confirm or double-consume seats.
- Keep the supplier `modules/umrah/umrah/issue` as the "mark confirmed" hook, but
  gut its fake-PNR logic (audit finding) — Phase 1 issues no ticket; ticketing is
  Phase 2.

---

## 6. Admin (Phase 1 subset — reuse `manage-umrah.php` UI patterns, new model)

New admin under `admin/umrah/*` (extend `umrahRoutes.php`; `ADMIN_AUTH()` + CSRF):
- **Departures list** — filter by month/status, load factor (confirmed/capacity),
  publish/unpublish/close, view bookings.
- **Create / Clone / Bulk-create** departures (the 12th & 28th generator) from
  the `NORMAL-14D` template; set regular/promo price, capacity, booking-close.
- **Bookings** — list + detail (commercial summary, payment schedule, snapshot).
- **Receivables** — due today / 7 / overdue (from `umrah_installments`).
- **Config** — pricing mode, hold minutes, low-stock, grace, plan defaults
  (start minimal; full tab set is Phase 2/§39).

Leave the legacy package CRUD in place (unused by the new flow) until cutover.

---

## 7. Seeds (from spec Part Z / X)

`ensureUmrahSchema` (or a one-time seeder route) seeds: `NORMAL-14D` template; 5
tiers (Standard bookable, others placeholder); `PP-50-25-25` + `PP-FULL` plans;
18 Standard features; the **6 departures** (Oct 12/28, Nov 12/28, Dec 12/28) as
`draft` with capacity 50 (spec: publish only after inventory confirmation);
module config seed (currency NGN, direct_sell_price, markups 0, tax off, hold 20,
grace 72, low-stock 10). **Departures seed as draft, not published** — matches
the spec's "publish after inventory confirmation".

---

## 8. Build sequence (each step verified before the next)

1. **Schema + seeds** — `ensureUmrahSchema` + `install/db.sql` + seed data.
   Verify: tables created live, seed rows present, `php -l`, boot 200.
2. **Pricing + quote service** — `umrah_price_quote` (+ precedence, no double
   markup). Verify: unit test quote for Standard = ₦2,490,000, no markup added.
3. **Hold service + expiry cron** — atomic hold, concurrency-safe. Verify:
   simulate two concurrent holds on last seat → only one wins.
4. **Booking + installment + schedule** — `umrah_booking_create` +
   `umrah_payment_schedule`. Verify: 50/25/25 amounts + dynamic 75/25 & 100%.
5. **Payment settle + price-lock (idempotent)** — `umrah_settle_payment`. Verify:
   deposit clears → confirmed + locked + hold consumed; duplicate call → no-op.
6. **Customer API v1** — departures/packages/quotes/holds/bookings/payments.
   Verify: end-to-end quote→hold→book→settle via HTTP (test data).
7. **Customer web** — landing + detail + wizard + confirmation + min dashboard;
   legacy redirect. Verify: renders, mobile CTA, no blank detail page.
8. **Admin** — departures CRUD/clone/bulk + bookings + receivables. Verify:
   create all 6 departures in the UI, publish, see them on `/umrah`.
9. **Regression + acceptance** — run the in-scope §76 checklist.

Steps 1–5 are backend (fully unit-verifiable here). 6–8 are HTTP-verifiable.

---

## 9. Reuse ledger (what we explicitly do NOT rebuild)

Routing/Router, `MARKUP()`/tax/currency helpers, payment gateway + wallet +
loopback, CSRF/ADMIN_AUTH, the generic `bookings`/`transactions`/`users` tables,
cron mechanism, admin UI component patterns (`manage-umrah.php` tabs, image
upload, JSON sub-editors), module id 35 + `umrah_settings`, notification helpers
(`NOTIFY`), invoice/PDF generation.

---

## 10. Honest risks & open decisions (need your input at build time)

- **Installment confirmation vs the 100%-only loopback** (§5) — the single
  biggest integration risk; the plan routes umrah through its own idempotent
  settle function to avoid touching every gateway.
- **Dashboard traveller collection** — Phase 1 stops at "complete later"; confirm
  whether you want even a *basic* traveller form now or purely Phase 2.
- **Migration/cutover** — old vs new run side-by-side; you decide when `/umrah`
  flips to the new flow and whether the 2 legacy packages are retired.
- **No live ticketing** — Phase 1 confirms + price-locks + collects money; it does
  NOT issue real tickets/visas (Phase 2). Customer copy must not imply otherwise.
- **This is multi-session.** I'll build in the step order above, verifying each,
  and check in between steps.

---

## 11. Phase 1 acceptance (subset of spec §76)

- [ ] 6 Oct–Dec Standard departures visible once published.
- [ ] Promo ₦2,490,000, old ₦2,800,000, save ₦310,000 shown correctly.
- [ ] 4 Madinah + 10 Makkah + full inclusions shown.
- [ ] Traveller count selectable; **server** calculates total.
- [ ] No double markup/tax on ₦2.49M.
- [ ] Inventory cannot oversell under concurrent checkout (atomic hold).
- [ ] Full payment works; 50/25/25 plan works; dynamic 75/25 & 100% correct.
- [ ] Cleared qualifying payment locks price + consumes inventory.
- [ ] Confirmation shows ref + amount paid/balance/next due.
- [ ] Payment confirmation idempotent (no double booking/seat).
- [ ] Active detail page never blank; legacy URL redirects.
- [ ] Admin creates/clones/bulk-creates departures + sets price/promo/capacity,
      publish/close, sees bookings + receivables — no code release.
- [ ] `php -l` clean, app boots 200, all step-verifications pass.

---

## BUILD LOG (autonomous)

**Step 1 — Schema + seeds ✅ (verified)**
- `ensureUmrahSchema()` + `seedUmrahPhase1()` in functions.php; called from config.php; 10 `CREATE TABLE IF NOT EXISTS` also in install/db.sql.
- Live: all 10 `umrah_*` tables created; seeds = 1 template (NORMAL-14D, 4+10 nights), 5 tiers (Standard bookable), 2 plans (PP-50-25-25, PP-FULL), 6 draft departures (12/28 Oct–Dec) each with a Standard departure-tier (promo 2,490,000 / regular 2,800,000 NGN, cap 50). Idempotent (re-ran twice, no dupes). install/db.sql block test-imports (10/10 tables), COMMIT intact.

**Step 2 — Pricing + quote service ✅ (verified)**
- `app/lib/umrah/services.php` (required in config.php): `umrah_price_resolve()` (§8.2 precedence), `umrah_price_quote()` (writes `umrah_quotes`, 20-min expiry).
- Live: Standard 1-pax unit = **exactly ₦2,490,000, NO markup added** (source `departure_tier_promo`); 5-pax total ₦12,450,000; savings ₦310,000 / 11.07%; quote row written. §8.3 double-markup bug prevented.

**Step 3 — Inventory hold + expiry ✅ (verified)**
- `umrah_hold_create()` (atomic: `->action()` transaction + `SELECT … FOR UPDATE`), `umrah_capacity_for()`, `umrah_hold_release()`, `umrah_hold_expire_sweep()`; cron route `GET /umrah_expire_holds`.
- Live: capacity 3 → oversell correctly blocked at the last seat; expiry sweep frees seats; cron endpoint returns JSON. No oversell possible.

**Next: Step 4** — booking + installment schedule (`umrah_booking_create`, `umrah_payment_schedule`).

**Step 4 — Booking + installment schedule ✅ (verified)**
- `umrah_payment_schedule()` (dynamic 50/25/25 → 75/25 → 100% by days-to-departure; last-row remainder correction so sum == total) + `umrah_booking_create()` (transactional: generic `bookings` row + `umrah_bookings` + §57 snapshot + `umrah_installments`) + `umrah_audit()`.
- Live: schedule math exact (far 1245000/622500/622500; mid 1867500/622500; near 2490000; sum=2490000). Round-trip quote→hold→book creates all rows + snapshot + audit; booking starts held/unpaid. NOTE: seeded departures are ~33 days out (today 2026-09-09) so they correctly produce the 2-installment 75/25 band, not 3.

**Next: Step 5** — payment settle + price-lock (idempotent): `umrah_settle_payment()`.

**Step 5 — Payment settle + price-lock (idempotent) ✅ (verified)**
- `umrah_settle_payment($db,$invoiceId,$amount,$currency,$txnId)` — transactional; allocates payment across pending installments in seq order; recomputes amount_paid/balance/payment_status; on a cleared qualifying (deposit) payment → consumes the hold, sets price_locked_at + booking_status=confirmed, reflects paid on the generic bookings row. IDEMPOTENT keyed on (invoice, txn).
- Live: deposit → confirmed + price_locked + hold consumed; duplicate same-txn → no-op (not doubled); balance payment → fully_paid / balance 0.

**Backend core (Steps 1–5) COMPLETE + verified.** Remaining: Step 6 customer API v1, Step 7 customer web, Step 8 admin, Step 9 acceptance.

**Step 6 — Customer API v1 ✅ (verified live over HTTP)**
- `app/routes/api/umrah/v1Routes.php` (registered in _routes.php): GET departures, GET packages/{slug}, GET departures/{id}, POST quotes, POST holds, POST bookings, GET bookings/{ref} (owner/admin only), POST bookings/{ref}/payments. Thin layer over the verified services; JWT/session user resolution; JSON.
- Live flow (published dep 1 temporarily): departures→quote(4,980,000)→hold(48 left)→booking(GGU-…, 75/25 installments)→guest GET/payments correctly 403. Reverted departures to draft. Fixed a cosmetic `remaining` cast (null when display_inventory_count off).

**Next: Step 7** — customer web (landing, package detail, booking wizard, confirmation, minimal dashboard, legacy redirect).

**Step 7 — Customer web ✅ (verified live)**
- Routes `app/routes/umrah/umrahV2Routes.php` (registered BEFORE legacy): `/umrah` landing, `/umrah/packages/{slug}` detail+wizard, `/umrah/booking/GGU-*` confirmation, `/umrah/detail/*` → 301 redirect to the stable package page.
- Views `app/views/modules/umrah/v2/`: landing.php (hero, month-grouped departure cards, tiers, inclusions, itinerary, support), detail.php (departure selector + Alpine booking wizard calling the v1 API quote→hold→book), confirmation.php (status, payment schedule, Pay-now, next steps).
- Route-collision fix: legacy homeRoutes `/umrah/` and detailRoutes `/umrah/detail/(.*)` neutralized (same-key overwrite risk — router returns first match but same key overwrites); v2 owns them.
- Live: landing 200 w/ ₦2,490,000 + 12 Oct card; detail 200 w/ wizard; legacy detail → 301 → /umrah/packages/normal-umrah-14-day; full wizard-API→confirmation journey renders (ref + 75/25 schedule + Pay now). Departures reverted to draft.

**Next: Step 8** — admin (departures list + create/clone/bulk-create + pricing + publish + bookings + receivables).

**Step 8 — Admin ✅ (verified)**
- `app/routes/admin/umrahV2Routes.php` (path prefix admin/umrah-manager, no collision with legacy admin/umrah): dashboard page, create/bulk(12th&28th)/clone departures, publish/close/draft (publish requires a priced tier + activates it), set price/capacity (guards capacity < confirmed pax), bookings list + receivables (due-7 / overdue). ADMIN_AUTH + CSRF on all mutations.
- Views: admin/umrah/v2/manager.php (KPIs, create/bulk forms, departures table w/ publish/close/clone), bookings.php.
- Live: all pages/actions 302-gated for anon; create-departure works (code + tier, price resolves 2,490,000), duplicate-date guard blocks re-create; code format tidied to UMR-YYYYMMDD-STD.

**Next: Step 9** — acceptance run (§76 in-scope checklist).

**Step 9 — Acceptance run ✅ (19/19 in-scope §76 checks pass)**
- Published all 6 seed departures, ran the checklist end-to-end, reverted to draft.
- Page/pricing (8/8): 6 departures visible; promo 2,490,000 / old 2,800,000 / save 310,000; server total 5pax=12,450,000 (no double markup); landing shows price + not blank; legacy detail → 301.
- Transactional (11/11): oversell blocked at last seat; booking created; installment plan created; payment settled → confirmed + price-locked + hold consumed + fully_paid; duplicate settle no-op (not double-charged).

## PHASE 1 COMPLETE — all 9 steps built + verified.
Final regression: 15 files php -l clean; app boots 200; cron 200. Departures left DRAFT (publish via admin/umrah-manager once real inventory is confirmed).

### Honest status / what remains outside Phase 1
- No live payment gateway round-trip executed (umrah_settle_payment is unit + acceptance verified, not driven through a real gateway callback). Wiring the gateway loopback to call umrah_settle_payment on the umrah invoice is the one integration step before real money flows.
- Phase 2 (unchanged scope): full traveller/passport/document/visa/ticket/rooming; premium tiers; addons; agent umrah; waitlist; notifications; advanced reports.
- Nothing committed (working tree).

**Gateway wiring ✅ (verified)** — `handle_payment_callback()` (app/lib/payment-gateway.php) now calls `umrah_settle_payment()` for umrah bookings on cleared payment, on BOTH the normal paid path (~L768) and the dev-mode-mismatch path (~L328). Idempotent. Integration-proven: settling with the exact callback args (invoice/amount/currency/txn) confirms the umrah booking + price-locks + consumes hold + fully_paid. The Phase-1 money path is now end-to-end (still not driven through a live external gateway, but the internal confirmation→settle chain is complete and verified).

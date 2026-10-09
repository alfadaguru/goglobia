# GoGlobia Hospitality PMS — Build Roadmap & Progress Tracker

> **Living document.** The single source of truth for *what we are building, in what
> order, and exactly where we are*. Updated after every increment. Scope of record is
> [`01b-hospitality-pms-catalogue.md`](01b-hospitality-pms-catalogue.md) (the 61
> modules); this file sequences and tracks them. **Current focus: the SUPPLIER module.**

---

## 0. Operating contract (the standing rules — apply to every line of work)

These are non-negotiable and were set by the owner. They bind every increment below.

1. **Don't guess, don't imagine, don't assume.** Every claim about the system is
   verified against the real code / schema / DB *first*. If something is unverified, it
   is labelled unverified — never stated as fact.
2. **Don't lie, don't hallucinate, don't ignore, don't neglect. Be real. Don't rush.**
   Status in this tracker reflects reality only — what is actually committed and (where
   possible) actually run. Time and tokens are **not** a constraint; correctness is.
   Nothing is glossed, skipped, or overstated.
3. **Double-check before writing any line of code.** Read the actual files involved
   first — every time — before editing. No edit on an unread file.
4. **Per-increment discipline:** implement → `php -l` lint → self-audit (trace the
   security/correctness paths) → commit with an honest message (state what is NOT done).
   A thing is only marked ✅ Done when it is committed AND self-audited; runtime-verified
   is tracked separately (see the Verification column) because the dev sandbox has no
   MySQL.

> **Runtime caveat (stated plainly):** the dev environment has no reachable MySQL, so
> nothing below is runtime-tested yet. Everything marked ✅ is **code-complete + lint-
> clean + source-audited**, not browser/DB-verified. Runtime verification happens on a
> real DB via `.claude/skills/run-goglobia` and is tracked in the Verification column.

---

## 1. A key product requirement captured (owner, this session)

**On registration a supplier ticks which services they will supply**, and **each
service must have its own landing page** (a "see more / read more" page the supplier can
open to learn what that service offers before/while onboarding).

- **Service-ticking at signup:** ✅ BUILT (increment 2 — `supplier_first_class_services()`
  + checkboxes + per-service counts in `app/routes/users/supplierSignupRoutes.php`).
- **Per-service supplier landing / "read-more" pages:** ✅ BUILT (increment S9 —
  `GET /supplier/services` overview + `GET /supplier/services/{service}` detail, content
  from `supplier_service_landing()`; "Read more" + "Learn about each service" links wired
  into the signup form). Flag-gated on `settings.supplier_registration` (404 when closed).

---

## 2. Where we are right now (honest status, verified against commits)

Branch `main`, **11 commits ahead of `origin/main` — NOT pushed.** (Push is the owner's
call.)

### Phase 1 — Supplier + Stays foundation: 8/8 increments code-complete

| # | Increment | Commit | Status | Runtime-verified |
|---|---|---|---|---|
| S1 | Schema (`ensureSupplierStaysSchema` + install/db.sql) | `0e8fe3c` | ✅ code-complete | ❌ not yet (no DB) |
| S2 | Onboarding service+count → admin quota | `801924c` | ✅ code-complete | ❌ |
| S3 | Owner-scoped `/supplier/stays/*` CRUD (security-critical) | `8258bce` | ✅ code-complete | ❌ |
| S4 | Supplier role-builder + property scoping (security-critical) | `ba8f41d` | ✅ code-complete | ❌ |
| S5 | Staff invitations + staff login scoping (security-critical) | `21a2b12` | ✅ code-complete | ❌ |
| S6 | Real inventory + atomic holds (security-critical) | `39af571` | ✅ code-complete | ❌ |
| S7 | Per-listing approval + public filter | `63df6da` | ✅ code-complete | ❌ |
| S8 | Branded-site + custom-domain foundation | `8403c73` | ✅ code-complete | ❌ |

Docs: spec suite `3e5a4e9`, 360 expansion `45a5511`, 61-module catalogue `8b555a6`.

### Known follow-ons already flagged (not silently dropped)
- **Hold→consume on payment** not wired (holds auto-expire safely meanwhile) — S6 note.
- **Custom-domain CNAME + TLS at the edge** deferred (infra decision) — S8 note.
- **Supplier room/rate/calendar editing** — DONE in S10 (owner-scoped, `supplier_can`-gated).
- **Rate *plans*** — S12 added normalized `stays_rate_plans`/`stays_rates` as a write-only
  MIRROR of `room_options` (canonical runtime source unchanged). A future increment can
  make a consumer (channel manager / OTA mapping) read the normalized model; today
  nothing reads it, so the live path is untouched.
- **Supplier payouts** (catalogue module 40) net-new — architecture in `00` §6.

---

## 3. The build roadmap — supplier module (focus), mapped to the 61 catalogue modules

Ordered so each increment is independently shippable, reuses the prior, and does the
security-critical / data-model-level work before features that depend on it. Catalogue
module numbers in brackets. Owner may re-order; this is the proposed sequence.

### Stage A — finish the supplier onboarding & self-service surface — ✅ COMPLETE (S9–S13)

| # | Increment | Catalogue modules | Depends on | Status |
|---|---|---|---|---|
| S9 | **Per-service supplier landing pages** ("read more" per ticked service) + link them into signup | 01, 17-adjacent | S2 | ✅ code-complete (not runtime-verified — no DB) |
| S10 | Supplier **room/rate/calendar self-management** (owner-scoped room/option/calendar CRUD under `/supplier/stays/{id}/rooms*`, gated by `supplier_can('rooms'\|'rates')`; options by stable `option_id`) | 05, 06 | S3, S6 | ✅ code-complete (not runtime-verified — no DB) |
| S11 | Supplier **reservations inbox** (owner sees bookings against their inventory; statuses; cancel/no-show) | 04, 07-adjacent | S3 | ✅ code-complete (not runtime-verified — no DB) |
| S12 | **Normalized rate plans** (`stays_rate_plans`/`stays_rates`) as a write-only MIRROR over `room_options` (bridge; live path unchanged) | 06 | S6, S10 | ✅ code-complete (not runtime-verified — no DB) |
| S13 | Supplier **onboarding wizard + go-live checklist** (progress meter; real-state derived) | 01, 02, 60 | S9, S10 | ✅ code-complete (not runtime-verified — no DB) |

### Stage B — the model-level ERP foundations (do before deep ERP features; from `01a`) — ✅ COMPLETE (S14–S17)

| # | Increment | Catalogue modules | Depends on | Status |
|---|---|---|---|---|
| S14 | **Org→brand→property→unit hierarchy** keys + backfill (+ configurable `accommodation_type`) — additive seam | 02, 03 | S1 | ✅ code-complete (not runtime-verified — no DB) |
| S15 | **Party model** (guests/companies/agents/vendors/owners/employees as typed parties) — additive seam + user backfill | 15, 18, 43, 48 | S14 | ✅ code-complete (not runtime-verified — no DB) |
| S16 | **Generalized RBAC** (permission catalogue superset) + per-role approval-limits engine (`supplier_can_approve`) — non-breaking over S4 | 45, 46 | S4 | ✅ code-complete (not runtime-verified — no DB) |
| S17 | **Event bus + platform audit** (`emit_event` reuses `triggerWebhook`; append-only `audit_events`) + dormant `workflow_rules` WHEN→IF→THEN seam | 46, 57, 61 | S17-self | ✅ code-complete (not runtime-verified — no DB) |

### Stage C — money (highest risk; build on foundations) — ✅ COMPLETE (S18–S20)

| # | Increment | Catalogue modules | Depends on | Status |
|---|---|---|---|---|
| S18 | Supplier **earnings accrual** (isolated pending→available ledger; read-only to supplier; idempotent per invoice) | 40 | S11, S15 | ✅ code-complete (not runtime-verified — no DB) |
| S19 | Supplier **payouts to bank** (Paystack transfers; admin-approved + kill-switch; 7 outbound guards) | 40 | S18 | ✅ code-complete (not runtime-verified — no DB) · follow-on: transfer webhook |
| S20 | **Hospitality GL** (double-entry, balanced+immutable) + COA + fiscal periods + cashier shifts — the property's own books (3rd ledger) | 34–38 | S15 | ✅ code-complete (not runtime-verified — no DB) |

### Stage D — guest & operations depth (per catalogue; sequence TBD with owner)
Groups D–F + J–L of the catalogue: PMS front-desk/night-audit (07,10), housekeeping
depth + laundry/minibar/lost&found (24–29), F&B/POS + recipes (30,31), events/MICE (16),
guest CRM + unified inbox + digital journey + loyalty + reputation (18–23), channel
manager (12), direct-booking engine (13), procurement + stores (41–43), apartment/owner/
lease/trust/utilities (47–51), smart-locks + IoT + incident (52–54), BI + AI (56,57),
API/integrations/migration (59,60). **Each becomes its own increment, specced + audited
before build.** Order decided with the owner as Stage A–C land.

---

## 4. Per-increment checklist (applied to every ☐ → ✅)

Before coding: **read** every file to be touched (rule 3). Then:
- [ ] Implement the increment.
- [ ] `php -l` every new/edited PHP file — must be clean.
- [ ] Self-audit: trace auth/ownership/money/oversell paths; verify invariants literally
      (re-run greps that return 0 before trusting them — known false-negative trap).
- [ ] Update `install/db.sql` if schema changed (mirror the ensure-function).
- [ ] Commit with an honest message stating what is NOT done / deferred.
- [ ] Update THIS tracker (status + commit hash + verification state).
- [ ] Runtime-verify on a real DB via `run-goglobia` when available → flip Verification.

---

## 5. Changelog (append-only; newest last)

- 2026-10 — Phase 1 (S1–S8) code-complete + committed (`0e8fe3c`…`8403c73`). Docs suite
  + 360 expansion + 61-module catalogue committed (`3e5a4e9`,`45a5511`,`8b555a6`). This
  tracker created. 11 commits local on `main`, unpushed.
- 2026-10 — **S9 done** (per-service supplier landing pages): `supplier_service_landing()`
  content helper; public `/supplier/services` + `/supplier/services/{service}` routes
  (flag-gated, 404 when closed); views `supplier/services/{index,show}.php`; "Read more"
  / "Learn about each service" links in the signup form. Lint-clean + self-audited (no
  route collision — detail route constrained to the 5 service keys; public-by-design,
  no DB writes, fully escaped). Not runtime-verified (no DB).
- 2026-10 — **S10 done** (supplier room/rate/calendar self-management): owner-scoped
  room/option/calendar CRUD appended to `supplierStaysRoutes.php`
  (`/supplier/stays/{id}/rooms`, `/rooms/save`, `/rooms/delete`,
  `/rooms/{roomId}/options/save`, `/options/delete`, `/rooms/{roomId}/calendar`,
  `/calendar/save`) + views `supplier/stays/{rooms,calendar}.php` + links in list/form.
  Gated by `supplier_can('rooms'|'rates', …, $stayId)` (IDOR + role + scope); CSRF on all
  writes; options addressed by STABLE `option_id` (never positional); room/option delete
  cleans `stays_inventory` + `stays_rooms_calendar`; calendar save whitelists option_ids
  and validates dates; unique keys prevent duplicate rate/inventory rows. No schema change
  (reuses existing tables). Lint-clean + self-audited. Not runtime-verified (no DB).
- 2026-10 — **S11 done** (supplier reservations inbox): new `supplierReservationsRoutes.php`
  (`GET /supplier/reservations`, `GET /supplier/reservations/{invoiceId}`,
  `POST …/{invoiceId}/action`) + views `supplier/reservations/{list,detail}.php` +
  dashboard link. Scoping crux: `bookings` has NO stay_id column, so the inbox is built
  from the owner's `stays.id` set, LIKE-prefiltered on `booking_data.hotel_id`, then EVERY
  row re-verified by decoding the JSON (LIKE is only a hint). Per-action IDOR: re-derives
  hotel_id from booking_data and calls `supplier_can('reservations','edit',$hotelId)`;
  CSRF on the write. Actions = cancel / no-show / clear-no-show (operational only — NO
  refund/payout; cancel flags cancellation_request for admin + releases availability holds
  by invoice ref). No schema change (writes existing bookings columns). Lint-clean +
  self-audited. Not runtime-verified (no DB).
- 2026-10 — **S12 done** (normalized rate plans): added `stays_rate_plans` +
  `stays_rates` (ensureSupplierStaysSchema + install/db.sql) as a WRITE-ONLY MIRROR over
  `room_options`, keyed by the stable `option_id`. New `app/lib/stays_rate_plans.php`
  (`stays_rate_plan_sync` upserts plan+rate per option, soft-removes deleted options;
  `stays_rate_plans_for_room` read helper, unused yet) required in config.php. Sync wired
  into supplier option save/delete + room delete AND admin option save/delete, so the
  mirror stays current whoever edits. CRITICAL: NO reader changed — the live
  booking/detail/listing/home path still reads `room_options` (canonical runtime source),
  so pricing/availability/behaviour are unchanged; nothing reads the normalized tables
  yet. Non-fatal (function_exists-guarded + try/catch). Lint-clean + self-audited. Not
  runtime-verified (no DB).
- 2026-10 — **S13 done** (onboarding wizard + go-live checklist): `supplier_onboarding_state($db,$owner)`
  in functions.php computes 6 go-live steps from REAL data (account active, stays service
  approved, property created, property has a room with a priced rate, submitted, live) +
  progress %. Reusable fragment `app/views/supplier/_onboarding.php` (compact on dashboard,
  full on the wizard; renders only while incomplete). New `GET /supplier/get-started`
  wizard route + `get-started.php` view; compact checklist embedded on the dashboard. No
  fake progress — every step derives from the tables the live path uses; read-only, no
  schema change. Lint-clean + self-audited. Not runtime-verified (no DB). **Stage A
  (S9–S13) COMPLETE.**
- 2026-10 — **S14 done** (org→brand→property→unit hierarchy; Stage B start): additive
  seam from 01a §1. New `supplier_orgs` (1 per supplier owner, unique owner_user_id) +
  `supplier_brands` (optional) tables; nullable `stays.org_id`/`brand_id` +
  `stays.accommodation_type` (default 'hotel', distinct from the existing stays_settings
  `stay_type` FK). One-time backfill (runs only when org_id column is first added, inc-7
  pattern) promotes each REAL-user owner to an org + stamps its properties; legacy/seed
  owners with no user row are skipped (left NULL). New `app/lib/supplier_hierarchy.php`
  (`supplier_org_ensure`/`supplier_org_for_owner`/`supplier_property_org`/
  `supplier_accommodation_types`) required in config.php. Property create stamps
  org_id + validated accommodation_type; edit persists it; form got an Accommodation-type
  select. CRITICAL: NO reader depends on the hierarchy — ownership still resolves on
  stays.user_id; live path untouched (populated seam, like S12). Schema in ensure-fn +
  install/db.sql. Lint-clean + self-audited. Not runtime-verified (no DB).
- 2026-10 — **S15 done** (party model): additive seam from 01a §4. New `parties` table
  (type guest/company/agent/vendor/owner/employee; nullable org_id + user_id link, UNIQUE
  uq_user). New `app/lib/supplier_parties.php` (`party_type_for_role`,
  `party_ensure_for_user`, `party_for_user`, `party_backfill_users`) required in
  config.php. Backfill creates a party for every existing user that lacks one — idempotent
  + re-entrant (per-row `has()` guard + uq_user key; bounded 2000/call), no one-time flag
  needed; runs from ensureSupplierStaysSchema. Supplier signup stamps the vendor party
  going forward. Role→type: customer→guest, agent→agent, supplier→vendor, admin→employee.
  CRITICAL: NO reader uses parties yet — identity still on users.user_id / booking rows;
  live path untouched. Subtype extension tables (guest_profile/company_account/…) deferred
  to the domains that consume them. Schema in ensure-fn + install/db.sql. Lint-clean +
  self-audited. Not runtime-verified.
- 2026-10 — **S16 done** (generalized RBAC + approval limits): from 01a §10, NON-BREAKING
  over S4. `supplier_role_modules()` UNCHANGED (role-builder + supplier_can() identical).
  Added `supplier_permissions_catalogue()` (superset: live 'stays' group active:true =
  supplier_role_modules(); finance/housekeeping/procurement groups defined but
  active:false, not offered until their code ships), `supplier_approval_limit_keys()`
  (discount %, refund/payment amount, rate override), `supplier_role_limit()` reader, and
  the gate `supplier_can_approve($db,$key,$amount,$stayId)` — admin/owner unrestricted,
  staff bound by configured limit, FAIL-CLOSED (unconfigured staff → deny/escalate),
  property IDOR re-checked. New `supplier_role_limits` table (ensure-fn + install/db.sql,
  uq_role_key). Limits are editable in the EXISTING role form (owner-guarded save;
  validated/clamped; cleared on role delete). supplier_can_approve has no caller yet
  (consuming domains = later discount/refund approval). supplier_can() untouched. Lint-
  clean + self-audited. Not runtime-verified.
- 2026-10 — **S17 done** (event bus + platform audit; Stage B COMPLETE): from 01a §11,
  EXTENDS the existing triggerWebhook/webhooks.php rather than duplicating. New
  `app/lib/supplier_events.php`: `platform_event_catalogue()` (canonical event names),
  `audit_log()` (append-only cross-domain trail → new `audit_events` table, complements
  logs_users/logs_webhooks), `emit_event()` (writes audit row + dispatches via
  triggerWebhook ONLY when a handler file exists — no "file not found" noise),
  `audit_recent()` reader. New tables `audit_events` (BIGINT id) + dormant `workflow_rules`
  (WHEN→IF→THEN store; NO engine executes rules yet — later increment). Real first
  producers wired: supplier signup emits `supplier.registered`; listing submit emits
  `property.submitted`. All emit calls non-fatal/function_exists-guarded; never throw into
  the caller even pre-migration. Schema in ensure-fn + install/db.sql. Lint-clean +
  self-audited. Not runtime-verified. **Stage B (S14–S17) COMPLETE.**
- 2026-10 — **S18 done** (supplier earnings accrual; Stage C start — HIGHEST RISK):
  DEDICATED, ISOLATED `supplier_earnings` ledger — NOT the customer/agent wallet spine
  (wallets.kind has no 'supplier'; routing supplier money through wallet_apply corrupts
  users.balance — avoided entirely). New `app/lib/supplier_earnings.php`:
  `supplier_earning_accrue_for_booking` (idempotent via UNIQUE invoice_id + locked
  SELECT…FOR UPDATE pre-check inside $db->action; accrues ONLY paid own-inventory stays
  with a real owner; amount = server-stored bookings.price_original net rate — never a
  client value), `supplier_earning_void_for_booking` (voids pending/available on cancel;
  never a 'paid' row), `supplier_earning_release_due` (pending→available after checkout +
  clearance days, cron-friendly, state-guarded), `supplier_earning_summary` (read-only
  totals). Table supplier_earnings (BIGINT id, uq_invoice, payout_id reserved for S19) in
  ensure-fn + install/db.sql. Wired: reservations inbox accrues idempotently on paid rows;
  supplier cancel voids; dashboard shows read-only pending/available/paid per currency. NO
  outbound money, NO wallet-spine touch, supplier has NO write path to earnings. Lint-clean
  + self-audited (7 payout-grounding risks checked). Not runtime-verified.
- 2026-10 — **S19 done** (supplier payouts to bank; OUTBOUND MONEY — highest risk):
  owner-chosen model = ADMIN-APPROVED manual trigger + master kill-switch
  `settings.supplier_payouts_live` (ships '0'). New `app/lib/supplier_payouts.php`:
  `supplier_payout_request` (owner requests from UNRESERVED available earnings; atomic
  SELECT…FOR UPDATE verify + reserve via payout_id; amount re-validated vs locked
  balance), `supplier_payout_approve_and_send` (admin; approve→kill-switch check→Paystack
  transferrecipient+transfer reusing paystack_dva_http/c1 secret; amount in integer KOBO;
  unique `reference` idempotency; mark 'processing' before the call; commit earnings
  available→paid only on accept; release on fail), `supplier_payout_reject` +
  `supplier_payable_available`. New table supplier_payouts (uq_reference) + users
  payout_* columns + settings.supplier_payouts_live (ensure-fn + install/db.sql). Routes:
  users/supplierPayoutsRoutes (owner save-bank/request), admin/supplierPayoutsRoutes
  (approve/reject queue). Views supplier/payouts.php + admin/suppliers/payouts.php; nav on
  dashboard + admin sidebar. 7 outbound risks all addressed. NO wallet-spine touch.
  ⚠ FOLLOW-ON: Paystack transfer WEBHOOK (transfer.success/failed) NOT wired — a later
  reversal still shows 'paid' locally; acceptable behind kill-switch + manual approval for
  first ship, add before scale. Not runtime-verified. **Next: S20 (hospitality
  GL/AR/AP/cashier/tax) — last Stage C item. STRONGLY recommend deploy + smoke-test the
  money stack (S18+S19) on the server before go-live.**
- 2026-10 — **S20 done** (hospitality GL + cashier; Stage C COMPLETE): the property's own
  double-entry books — a THIRD ledger, DISTINCT from the customer wallet spine AND the
  supplier payout spine (verified: supplier_ledger.php touches none of those tables). New
  `app/lib/supplier_ledger.php`: `gl_seed_accounts` (minimal hospitality COA per org,
  seeded on org creation), `gl_post` (double-entry poster that REFUSES any unbalanced
  entry — sum(debit)≠sum(credit) → rejected; line = debit XOR credit; atomic; immutable),
  `gl_trial_balance`, `gl_period_is_open` (refuses posting into a closed fiscal period,
  fail-closed), cashier `cashier_shift_open`/`cash_movement_add`/`cashier_shift_close`
  (expected-vs-actual over/short). New tables chart_of_accounts, journal_entries,
  journal_lines, fiscal_periods, cashier_shifts, cash_movements (ensure-fn + install/db.sql).
  COA auto-seeded via supplier_org_ensure. NO auto-posting from folios/bookings yet (Stage
  D PMS work); nothing in the live path posts to the GL. Lint-clean + self-audited. Not
  runtime-verified. **Stage C (S18–S20) COMPLETE. Phase 1 + ERP-foundations roadmap
  (S9–S20) DONE. Next: Stage D (guest/ops depth per catalogue) — sequence with owner; OR
  deploy + smoke-test everything first (strongly recommended before any go-live).**

> **Deploy note (confirmed this session):** `install/db.sql` IS what the admin DB tool
> (`/admin/updates/database`) reads — it emits each missing table verbatim (keys/unique
> indexes included) + `ALTER … ADD COLUMN` for new columns. The BACKFILLS (org promotion,
> party creation) are NOT run by that tool — they live in `ensureSupplierStaysSchema()`
> and run on a page load. Deploy = /updates (files) → admin DB Update (DDL) → load an
> admin page (backfills, idempotent).

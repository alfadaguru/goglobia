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

### Stage D — guest & operations depth (per catalogue; sequence TBD with owner) — IN PROGRESS

| # | Increment | Catalogue modules | Status |
|---|---|---|---|
| S21 | **PMS folio / front-desk** — guest folio + check-in/out; checkout posts to the GL (S20) + releases the supplier earning (S18) | 07, 02 | ✅ code-complete (not runtime-verified — no DB) |
| S22 | **Housekeeping + physical-room assignment** — physical rooms w/ clean/dirty/inspected/OOO; assign at check-in; checkout→dirty. Overlay only (pooled inventory untouched) | 24, 02 | ✅ code-complete (not runtime-verified — no DB) |
| S23 | **Maintenance / work-orders + OOO→inventory** — tickets (open/in_progress/resolved); OOO a physical room reduces pooled `stays_inventory` by 1/option/date (floored at held), reversible | 25, 06 | ✅ code-complete (not runtime-verified — no DB) |
| S24 | **F&B / POS (charge-to-room)** — outlets + menu + orders; settle cash OR charge-to-room → posts a `charge` to the in-house guest's folio (S21), server-computed total | 30 | ✅ code-complete (not runtime-verified — no DB) |
| S25 | **Night audit (daily close)** — per-property business date; flag no-shows, snapshot occupancy/ADR/RevPAR, roll date; idempotent per (property,date) | 07, 16 | ✅ code-complete (not runtime-verified — no DB) |
| S26 | **Apartment-owner model + owner statements** — owners + management agreements (mgr commission); statement = earnings − platform − mgr commission − expenses → owner payout | 47, 48 | ✅ code-complete (not runtime-verified — no DB) |
| S27 | **Direct-booking engine** — branded-site/walk-in booking (source=direct_site) consuming the SAME pooled inventory via stays_hold_create; server-priced; flows into reservations/folio/earnings | 13, 02 | ✅ code-complete (not runtime-verified — no DB) |
| S28 | **BI / operations dashboard** — READ-ONLY KPIs from real tables: earnings, occupancy/ADR/RevPAR trend, F&B sales, channel mix, reservations, HK/work-order counts | 56 | ✅ code-complete (not runtime-verified — no DB) |
| S29 | **Guest CRM** — returning-guest profiles aggregated from bookings by email (stays, spend, history, properties) + owner notes/VIP/tags | 18 | ✅ code-complete (not runtime-verified — no DB) |
| S30 | **Loyalty** — per-org points ledger keyed on guest email; auto-earn on paid stay at checkout (idempotent); tiers; manual adjust/redeem; balance on guest profile | 22 | ✅ code-complete (not runtime-verified — no DB) |
| S31 | **Reviews / reputation** — tokened post-stay guest review (1-5 + comment); owner moderation (publish/hide); published reviews roll up to `stays.rating`+`rating_count` (the live public field) | 23 | ✅ code-complete (not runtime-verified — no DB) |
| S32 | **Procurement + stores** — vendors, stock items, purchase orders + lines, GRN (receive → stock on_hand++ + DR 6000/CR 2000 to GL); low-stock alert | 41, 42 | ✅ code-complete (not runtime-verified — no DB) |
| S33 | **Events / MICE** — function spaces + event bookings (enquiry→confirmed→completed) + event folio; completion posts DR 1200/CR 4000 to GL | 16 | ✅ code-complete (not runtime-verified — no DB) |


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
- 2026-10 — **S21 done** (PMS folio / front-desk; STAGE D START): the piece that ACTIVATES
  the dormant S18/S20 seams. New `app/lib/supplier_folio.php`: `folio_get_or_create`
  (idempotent, UNIQUE invoice_id + locked re-check; seeds room+tax+paid from the booking's
  GUEST figures — price_markup/tax, distinct from the supplier NET earning),
  `folio_add_item` (front-desk extra/charge/payment/refund on an open folio),
  `folio_checkin`, `folio_checkout` (finalize → gl_post the balanced guest bill
  [DR Guest-AR / CR revenue+tax ; DR Cash / CR Guest-AR] → release the supplier earning
  pending→available via new `supplier_earning_release_for_invoice`), `folio_totals`,
  `folio_stay_state`. PMS stay-state (confirmed→checked_in→checked_out) in booking_data
  (no bookings schema change). New tables stays_folios (uq_invoice) + stays_folio_items
  (ensure-fn + install/db.sql). Wired into the S11 reservation detail + /action handler
  (check_in/check_out/folio_add), reusing its supplier_can('reservations','edit',$hid) gate
  + CSRF. Reads bookings, writes only new tables + GL — live booking/payment path untouched.
  Idempotent + state-guarded (no double-post/double-release); GL failure never blocks
  checkout. Lint-clean + self-audited. Not runtime-verified. **Next Stage D module = owner's
  call. STRONGLY recommend deploy + smoke-test S1–S21 before more / go-live.**
- 2026-10 — **S22 done** (housekeeping + physical-room assignment): new
  `app/lib/supplier_housekeeping.php` — physical rooms (stays_physical_rooms) under a room
  TYPE, hk_status clean/dirty/inspected/out_of_order with a validated transition machine
  (`hk_status_transition_ok`); `hk_assign_room_to_booking` (assign at check-in; validates
  owned+assignable+not-in-use), `hk_rooms_in_use` (decoded-booking_data verify, not just
  LIKE), `hk_room_set_status`, `hk_assignable_rooms`, `hk_on_checkout` (checkout→dirty,
  guarded against OOO). New table stays_physical_rooms (uq stay+number) ensure-fn+db.sql.
  Routes users/supplierHousekeepingRoutes (board + status + add). Views
  supplier/housekeeping.php + physical-room add form on the rooms page + check-in room
  picker on reservation detail + dashboard link. Wired hk_on_checkout into folio_checkout.
  CRITICAL boundary: OVERLAY only — writes stays_physical_rooms + booking_data; NEVER
  touches stays_inventory (pooled sellability/anti-oversell unchanged). supplier_can('rooms')
  gated + CSRF. Lint-clean + self-audited. Not runtime-verified. **Next Stage-D module =
  owner's call (maintenance/OOO→inventory, F&B/POS, night audit, channel mgr, …). STRONGLY
  recommend deploy + smoke-test S1–S22 before more / go-live.**
- 2026-10 — **S23 done** (maintenance / work-orders + OOO→inventory): new
  `app/lib/supplier_maintenance.php` — work-order tickets (wo_create/wo_set_status;
  open→in_progress→resolved, priority, room/area) + OUT-OF-ORDER blocks that, UNLIKE
  housekeeping, DO touch stays_inventory: ooo_block_create decrements pooled
  available_count by 1 per (option,date) for the room's TYPE — inside $db->action() with
  per-row FOR UPDATE, floored at active-hold usage and ≥0 (anti-oversell invariant kept:
  can't remove already-committed capacity); ooo_block_clear restores +1, state-guarded,
  never over-restores. Per-UNIT decrement (not closed=1) so OOO one room of a type leaves
  the rest sellable. Tables stays_work_orders + stays_ooo_blocks (ensure-fn + db.sql).
  Routes added to supplierHousekeepingRoutes (maintenance list + work-order create/status
  + ooo create/clear); view supplier/maintenance.php + dashboard link. supplier_can('rooms')
  + CSRF. Known edge (noted, not hidden): if an owner edits the S10 calendar absolute count
  while an OOO block is active, the ±1 delta is overwritten — acceptable (explicit override);
  block row persists for audit. Lint-clean + self-audited. Not runtime-verified. **Next
  Stage-D = owner's call (F&B/POS, night audit, channel mgr, owner statements, …). STRONGLY
  recommend deploy + smoke-test S1–S23 before more / go-live.**
- 2026-10 — **S24 done** (F&B / POS, charge-to-room): new `app/lib/supplier_pos.php` —
  outlets + menu items + orders + order items + payments. pos_order_create/add_item
  (price snapshot at add-time), pos_order_total (SERVER-side recompute), pos_order_settle_cash,
  pos_order_settle_charge_to_room (posts the order total as a 'charge' folio line via
  folio_add_item). _pos_settle: for 'room', validates booking is own-inventory for THIS
  property ($hid===$stayId) + checked_in + folio open BEFORE acting; folio-post failure
  rolls back the whole settlement; re-locks order FOR UPDATE + state-guard (no
  double-settle). pos_checked_in_reservations (charge targets). Tables stays_outlets,
  stays_menu_items, pos_orders, pos_order_items, pos_payments (ensure-fn + db.sql). Routes
  users/supplierPosRoutes (outlets/menu/order/settle), views supplier/pos/{outlets,outlet}.php
  + dashboard link. supplier_can('rooms',…,$stayId) + CSRF; server-computed amounts.
  KNOWN SIMPLIFICATION (noted): a charge-to-room line posts into GL Room Revenue (4000) at
  checkout, not a separate F&B Revenue (4100) account — correct totals, coarse classing;
  refine when per-outlet GL mapping lands. Not runtime-verified. **Next Stage-D = owner's
  call (night audit, channel mgr, owner statements, …). STRONGLY recommend deploy +
  smoke-test S1–S24 before more / go-live.**
- 2026-10 — **S25 done** (night audit / daily close): new `app/lib/supplier_night_audit.php`
  — per-property business date (stays_business_date, seeds to today) + daily-close
  snapshots (stays_night_audits, UNIQUE stay+date). night_audit_run: flag no-shows
  (confirmed + checkin≤biz-date never checked in → pms_no_show + release holds; NO money
  touched), snapshot arrivals/departures/in-house/rooms-sold/room-revenue/ADR/RevPAR/
  occupancy (occupancy null→"—" when no physical rooms; all div-guarded), then ROLL the
  business date +1. Idempotent per (stay,date): UNIQUE + locked FOR UPDATE re-check; date
  only advances on a fresh snapshot. HONEST MODEL NOTE: our folio seeds whole-stay room+tax
  at check-in, so night audit does NOT re-post per-night room charges (would double-bill) —
  it's no-shows + snapshot + roll. Tables in ensure-fn + db.sql. Routes added to
  supplierHousekeepingRoutes (/supplier/night-audit board + /run); view
  supplier/night-audit.php + dashboard link. supplier_can('reservations') + CSRF. Not
  runtime-verified.
- 2026-10 — **S26 done** (apartment-owner model + owner statements): new
  `app/lib/supplier_owners.php` — owner_create (stays_owners), owner_agreement_set
  (stays_management_agreements; one active per property, supersede-on-new; mgr commission
  % + fixed fee), owner_expense_add (stays_owner_expenses), owner_statement_preview +
  owner_statement_generate (stays_owner_statements). Statement aggregates
  supplier_earnings (S18) for the property+period (exclude void): owner_payout =
  operator_net (= net after PLATFORM commission) − manager_commission (pct×gross+fixed) −
  expenses. Server-derived money; attaches in-window unattached expenses to the statement
  (no double-count next period). OWNER-ONLY (SUPPLIER_AUTH) + CSRF; org-scoped (owner +
  property must belong to org; statement route re-checks stays.org_id). HONEST SCOPE: NO
  money movement / NO GL posting — generates statements only; owner bank payout + trust
  accounting are later. 4 tables (ensure-fn + db.sql). Routes users/supplierOwnersRoutes;
  view supplier/owners.php + owner-only dashboard link. Not runtime-verified. **Next
  Stage-D = owner's call (channel mgr, direct-booking engine, procurement, smart-locks,
  BI, loyalty, reviews, events). STRONGLY recommend deploy + smoke-test S1–S26 before
  go-live.**
- 2026-10 — **S27 done** (direct-booking engine): new `app/lib/supplier_direct_booking.php`
  — direct_booking_quote (server price = option.price × nights + calculateTax[tax_amount])
  + direct_booking_create (places a pooled-inventory hold via stays_hold_create FIRST —
  same anti-oversell pool as marketplace/walk-in — then inserts a bookings row
  module_type='stays', source='direct_site', commission=0, price_original=room_total,
  payment_status='unpaid' pay-at-property; releases hold if insert fails). Flows into
  reservations(S11)/folio(S21)/earnings-on-paid(S18) unchanged. Route
  POST /supplier/stays/site/{id}/book (desk action, supplier_can('reservations','edit')
  + CSRF) + a working booking form on the branded preview (replaces the disabled Book
  button). NO schema change (reuses bookings/stays_holds/stays_inventory). Self-audit
  caught + fixed a tax-key bug (calculateTax returns tax_amount, not tax/amount — was
  silently zeroing tax). Live marketplace path untouched. HONEST: fully-public
  guest-facing booking still needs the S8 host-routing edge (infra-deferred) — this is
  the desk/walk-in + preview surface. Not runtime-verified.
- 2026-10 — **S28 done** (BI / operations dashboard, READ-ONLY): new `app/lib/supplier_bi.php`
  — bi_reservations_by_status (status + channel mix from booking_data.source, decoded-verify),
  bi_audit_trend (stays_night_audits occupancy/ADR/RevPAR), bi_fnb_sales (settled
  pos_orders + pos_payments by tender), bi_ops_counts (housekeeping + open work orders),
  bi_dashboard (assembles + reuses supplier_earning_summary). Route GET /supplier/insights
  (supplier_can('reservations','view'), owner's stay-id set). View supplier/insights.php
  (KPI cards + inline-SVG occupancy sparkline + channel-mix bars + trend table) + dashboard
  link. NO writes, NO schema change — every metric from a confirmed real column. Self-audit
  caught a Tailwind precompile gap: bg-violet-500/600 are NOT in the compiled build.css
  (PR#102 build-time compile) so those fills rendered invisible — FIXED in insights.php AND
  retro-fixed S13 _onboarding.php progress bar/step marker + S9 services/show.php step
  numbers to inline background-color:#7c3aed. Not runtime-verified.
- 2026-10 — **S29 done** (guest CRM): new `app/lib/supplier_guests.php` — guest_list
  (profiles aggregated from an org's bookings BY EMAIL: stays, cancelled, total_spent
  [paid+non-cxl only], first/last seen, properties; decoded-verify vs owned set),
  guest_history (per-guest stays + profile), guest_profile_meta/guest_profile_save (the
  ONLY writable part — note/VIP/tags per (org,email) in stays_guest_profiles),
  guest_token/decode (base64url email in URL, re-validated + ownership-checked). Routes
  users/supplierGuestsRoutes (/supplier/guests list + /{token} detail + /{token}/note);
  views supplier/guests/{list,detail}.php + dashboard link. supplier_can('reservations')
  gated; note-save re-checks the guest has a booking with this owner (no note-spam).
  Table stays_guest_profiles (uq org+email) ensure-fn + db.sql. Read-mostly, bounded 5k
  scan. Not runtime-verified.
- 2026-10 — **S30 done** (loyalty points): new `app/lib/supplier_loyalty.php` —
  loyalty_config/save (per-org enable + points_per_currency), loyalty_accrue_for_booking
  (idempotent earn on a PAID own-inventory stay: UNIQUE(org,invoice) + locked pre-check;
  points = round(price_markup × rate); keyed on guest email S29), loyalty_manual
  (adjust/redeem; locks SUM FOR UPDATE + refuses balance<0), loyalty_guest_summary
  (balance/lifetime/tier), loyalty_guest_ledger. Tiers by lifetime points (config).
  Tables stays_loyalty_config + stays_loyalty_ledger (ensure-fn + db.sql; UNIQUE org+invoice
  allows many NULL manual rows + one earn/invoice). Accrual WIRED into folio_checkout
  (next to earnings release + hk). Config form (owner-only) on guest list; balance/tier +
  manual adjust/redeem + ledger on guest detail. supplier_can gated; manual re-checks guest
  has a booking w/ owner. SOFT currency — no cash value, redeem is a ledger row only. Not
  runtime-verified.
- 2026-10 — **S31 done** (reviews / reputation): new `app/lib/supplier_reviews.php` —
  review_token/decode (binds invoice), review_eligible_booking (own-inventory +
  checked_out|paid + not-already-reviewed), review_submit (UNIQUE(stay,invoice) + locked
  pre-check; rating 1-5; starts 'pending'), review_set_status (owner publish/hide →
  review_rollup), review_rollup (AVG of PUBLISHED → writes ONLY stays.rating +
  stays.rating_count = the live public field), review_list_for_org. Table stays_reviews
  (uq stay+invoice) + new stays.rating_count col (ensure-fn + db.sql CREATE/ALTER).
  PUBLIC capture GET/POST /stay-review/{token} (no login; token binds invoice; CSRF;
  re-validates eligibility). OWNER moderation /supplier/reviews + /status
  (supplier_can('reservations','edit') + stays.user_id===owner). Views stays/review.php
  (public star form) + supplier/reviews.php + dashboard link + copy-review-link on the
  checked-out reservation detail. Only live-stays write = the rating/rating_count rollup.
  Not runtime-verified.
- 2026-10 — **S32 done** (procurement + stores): new `app/lib/supplier_procurement.php` —
  proc_vendor_create, proc_item_create (stock SKU w/ on_hand + reorder_level), proc_po_create
  (draft) / proc_po_add_line (draft only; recomputes total) / proc_po_order (draft→ordered,
  needs ≥1 line), proc_po_receive (GRN: atomic $db->action + re-lock status guard →
  increments each item on_hand [row-locked] + posts balanced DR 6000/CR 2000 via gl_post +
  marks received, idempotent), proc_low_stock. Tables stays_vendors / stays_stock_items /
  stays_purchase_orders (uq_reference) / stays_po_lines (ensure-fn + db.sql). Routes
  users/supplierProcurementRoutes (OWNER-ONLY SUPPLIER_AUTH + CSRF, org-scoped); view
  supplier/procurement.php + dashboard link. Server-computed totals. HONEST: GRN posts to
  generic Operating Expense (6000) [no inventory-asset COA code]; does NOT pay the vendor
  (settling AP = later); stock still increments even if GL post is skipped (logged). Not
  runtime-verified.
- 2026-10 — **S33 done** (events / MICE): new `app/lib/supplier_mice.php` (named _mice to
  avoid clashing with the S17 supplier_events.php bus — I nearly overwrote that file, caught
  it, git-restored it, verified emit_event/audit_log intact). mice_space_create,
  mice_event_create (enquiry), mice_folio_add (venue/catering/extra/payment/refund on a
  non-completed event), mice_event_totals, mice_event_set_status (enquiry→confirmed→completed
  |cancelled; on COMPLETE: atomic $db->action + re-lock status guard → balanced DR 1200/CR
  4000 gl_post, idempotent), mice_events_for_org. Tables stays_event_spaces / stays_events
  (uq_reference) / stays_event_items (ensure-fn + db.sql). Routes users/supplierEventsRoutes
  (supplier_can('reservations',…,$stayId) + CSRF); view supplier/events.php + dashboard link.
  HONEST: core MICE only (no BEO/catering-menus/seating/equipment/deposits/space-conflict
  check); event revenue posts as one line. Not runtime-verified. **Stage D broad coverage
  DONE. Remaining catalogue items are integration-heavy (channel manager — needs external
  OTA creds) or vision-only (smart-locks SDK). STRONGLY recommend owner deploy + smoke-test
  S1–S33 before go-live / before any integration module.**

> **Deploy note (confirmed this session):** `install/db.sql` IS what the admin DB tool
> (`/admin/updates/database`) reads — it emits each missing table verbatim (keys/unique
> indexes included) + `ALTER … ADD COLUMN` for new columns. The BACKFILLS (org promotion,
> party creation) are NOT run by that tool — they live in `ensureSupplierStaysSchema()`
> and run on a page load. Deploy = /updates (files) → admin DB Update (DDL) → load an
> admin page (backfills, idempotent).

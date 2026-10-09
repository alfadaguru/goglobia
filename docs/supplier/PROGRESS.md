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
- **Rate *plans*** are still JSON `room_options` + a stable `option_id`, not normalized
  `stays_rate_plans`.
- **Supplier payouts** (catalogue module 40) net-new — architecture in `00` §6.

---

## 3. The build roadmap — supplier module (focus), mapped to the 61 catalogue modules

Ordered so each increment is independently shippable, reuses the prior, and does the
security-critical / data-model-level work before features that depend on it. Catalogue
module numbers in brackets. Owner may re-order; this is the proposed sequence.

### Stage A — finish the supplier onboarding & self-service surface (near-term focus)

| # | Increment | Catalogue modules | Depends on | Status |
|---|---|---|---|---|
| S9 | **Per-service supplier landing pages** ("read more" per ticked service) + link them into signup | 01, 17-adjacent | S2 | ✅ code-complete (not runtime-verified — no DB) |
| S10 | Supplier **room/rate/calendar self-management** (owner-scoped room/option/calendar CRUD under `/supplier/stays/{id}/rooms*`, gated by `supplier_can('rooms'\|'rates')`; options by stable `option_id`) | 05, 06 | S3, S6 | ✅ code-complete (not runtime-verified — no DB) |
| S11 | Supplier **reservations inbox** (owner sees bookings against their inventory; statuses; cancel/no-show) | 04, 07-adjacent | S3 | ✅ code-complete (not runtime-verified — no DB) |
| S12 | **Normalized rate plans** (`stays_rate_plans`/`stays_rates`) with the `room_options` compatibility bridge | 06 | S6, S10 | ☐ planned |
| S13 | Supplier **onboarding wizard + go-live checklist** (progress meter; property setup %) | 01, 02, 60 | S9, S10 | ☐ planned |

### Stage B — the model-level ERP foundations (do before deep ERP features; from `01a`)

| # | Increment | Catalogue modules | Depends on | Status |
|---|---|---|---|---|
| S14 | **Org→brand→property→unit hierarchy** keys + backfill (+ configurable `accommodation_type` per property) | 02, 03 | S1 | ☐ planned |
| S15 | **Party model** (guests/companies/agents/vendors/owners as typed parties) | 15, 18, 43, 48 | S14 | ☐ planned |
| S16 | **Generalized RBAC** (permission→role→user catalogue) + approval-limits engine | 45, 46 | S4 | ☐ planned |
| S17 | **Workflow/event bus** (WHEN→IF→THEN) + platform **audit event** | 46, 57, 61 | S17-self | ☐ planned |

### Stage C — money (highest risk; build on foundations)

| # | Increment | Catalogue modules | Depends on | Status |
|---|---|---|---|---|
| S18 | Supplier **earnings accrual** (pending→available ledger; read-only to supplier) | 40 | S11, S15 | ☐ planned |
| S19 | Supplier **payouts to bank** (Paystack transfers; the 7 outbound-money guards) | 40 | S18 | ☐ planned |
| S20 | **Hospitality GL + AR/AP + cashier/bank-rec + tax engine** | 34–38 | S15 | ☐ planned |

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
  self-audited. Not runtime-verified (no DB). **Next: S12 (normalized rate plans) or S13
  (onboarding wizard) — Stage A tail.**

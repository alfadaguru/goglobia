# GoGlobia — Travel-Agent ERP / B2B Portal — Complete Spec (v3, LOCKED)

**Status:** LOCKED — owner approved all decisions 2026-10-24; build starts at P0.
**Date:** 2026-10-24
**Branch when built:** `feat/agent-erp` (new; not started)
**Supersedes:** v1/v2. This v3 is grounded in a 10-reader line-by-line audit of
the real code. Every "exists today" claim is cited to a `file:line` read during
research; everything under **BUILD** does not exist yet.

## OWNER DECISIONS — LOCKED (2026-10-24)
1. **Admin approval: YES.** Agent signup → `status='pending'` → admin approves
   (supplier-style gate) before wholesale pricing / wallet / branded docs.
2. **Agency self-markup: YES.** Agencies set their own client price over our B2B
   cost; the branded invoice shows the agent's price; the agent sees
   "You pay X · Client price Y · Your margin Y−X". Supplier net stays hidden.
3. **Staff login: DECIDED (assistant, code-grounded).** Agent staff use the
   **existing supplier-staff model**: email invite → public token-gated accept
   (single-use, 7-day) → set password → linked as a `customer`-role user tied to
   the agency via `agent_staff`. They log in through the **normal main-site
   login** and land in `/agent/*`, resolved by `agent_staff_context`. No separate
   login page. (Verified pattern: `supplierStaffRoutes.php`, which also refuses to
   invite an existing supplier/agent/admin — we copy that guard.)
4. **Branded docs: ALL services.** Generalise the branding hook across every
   module PDF + emails; copy the stays page pattern to the rest.

> **SERVICE COUNT CORRECTION (verified in code 2026-10-25):** the platform has
> **11 bookable services**, not 9: flights, stays, tours, cars, umrah, visa, bus,
> rail, ferries, esim, **insurance**. The earlier "9" counted only the
> `booking_pdf` PDF templates under `app/views/notifications/emails/<module>/` —
> and **esim + insurance have NO booking_pdf template** (verified per-file: the
> other 9 incl. ferries DO have one; esim fell through to the stays template).
> So P4 (branded docs) must (a) brand the 9 existing PDFs AND (b) add a generic
> branded PDF/voucher template for esim + insurance. The
> Booking Centre (P3) covers all 11.
5. **Scope: FULL 360° travel-agent ERP**, everything piped into our system, in
   detail. Build P0→P8, no steps skipped.

> Rules honoured throughout: owner deploys (assistant only codes/commits/pushes,
> no deploy steps, no SQL run by the assistant); verify-don't-guess (file:line on
> every claim); customers/clients NEVER see suppliers or our net price; new POST
> routes get CSRF; new columns get an idempotent `ensure*` + `install/db.sql`.

---

## 0. Owner's request, verbatim → how this spec covers it

Owner: *"seamless onboarding; a booking centre where agents book services for
their passengers; agent pays only from wallet; wallet funded via a generated DVA;
staff management with RBAC (default + custom roles); upload logo + brand colours;
all tickets AND invoices in their logo/brand + contact info to share with clients;
a complete ERP for travel agents while letting them search & book our services;
create trip plans; and a lot of things."* Plus: *"build the agent booking centre
a NEW dashboard … everything in full complete."*

| # | You said | Covered in |
|---|---|---|
| 1 | Seamless onboarding | §A1 |
| 2 | Booking centre — book for passengers | §A2 (new dashboard) |
| 3 | Pay only from wallet | §A3 |
| 4 | Wallet funded by generated DVA | §A4 |
| 5 | Staff management, RBAC, default + custom roles | §A5 |
| 6 | Upload logo + brand colours | §A6 |
| 7 | ALL tickets + invoices in agent brand + contact | §A7 |
| 8 | Complete ERP | §A8 + all of §B |
| 9 | Search & book our services | §A2 |
| 10 | Create trip plans | §A9 |
| 11 | "a lot of things" | §B (12 modules) |
| NEW | A dedicated NEW dashboard, full/complete | §1 + §A0 |

---

## 1. Architecture decision: a dedicated `/agent/*` portal modelled on the
## existing supplier portal (which is a proven, production multi-tenant ERP)

The codebase already contains a **full B2B portal** for suppliers (role='supplier')
— ~80 route files, a context-aware dashboard, staff RBAC, onboarding checklist,
and a white-label branded-site builder. It is the **1:1 architectural template**
for the agent portal. Verified:

- `/supplier/dashboard` route gated by `SUPPLIER_OR_STAFF_AUTH($db)` +
  `supplier_acting_context($db)` which resolves **staff → parent owner**
  (`supplierDashboardRoutes.php:12-30`).
- Shell = shared `includes/header.php`/`footer.php` + a dedicated
  `supplier/sidebar.php` with a `.supplier-sidebar-open` body class
  (`header.php:17`); context-aware nav gated per item by `supplier_can(...)`
  (`supplier/sidebar.php:25-27,111-198`).
- **Data-driven onboarding checklist** computed fresh each load (no wizard-state
  table) — `supplier_onboarding_state()` + `supplier/_onboarding.php` +
  `supplier/get-started.php`.
- White-label branding: theme JSON in a `*_site.theme` column, validated
  (hex/URL/text), rendered via CSS custom properties `--wl-*`
  (`supplier_sitebuilder.php:21-65`, `supplier/stays/site/_layout.php:79-89`).

**Decision:** build `/agent/*` as a sibling of `/supplier/*`, copying these
patterns (NOT forking the supplier *logic* — parallel `agent_*` helpers so a
change to one never silently changes the other). Supplier routes load
unconditionally in `_routes.php:53-58`; agent routes load the same way.

### §A0 — The NEW Agent Dashboard (owner's explicit ask)
A dedicated workspace at `/agent/dashboard` (mirrors `supplier/dashboard.php`):
top-bar with wallet balance + DVA NUBAN + tier; onboarding checklist; KPI cards
(today's bookings, wallet, credit, earnings paid-only, staff online); a module
launcher grid (Booking Centre, Trips, Clients, Bookings, Wallet, Staff, Reports,
Branding, API); recent bookings. Own `agent/sidebar.php`. Staff see a
role-scoped subset (owner-only cards hidden, exactly like
`supplier/dashboard.php:174-228`).

---

## 2. Verified foundation — exists-vs-build master matrix

| Capability | Exists | Evidence (file:line) | Agent work |
|---|---|---|---|
| Agent = users.role='agent' + `agencies` (logo col) | ✅ | db.sql:30-43; seed db.sql:31768 | reuse |
| `CHECK_AGENT()` onboarding-enforce hook | ✅ | functions.php:5965-5996 | reuse as wizard gate |
| MARKUP() B2B/B2C + custom + tier discount | ✅ | functions.php:4565-4774 | reuse |
| Agent tiers (metal, by lifetime top-up) | ✅ | functions.php:6650-6675; wallet.php:392-430 | reuse |
| Wallet spine (atomic, idempotent, per-ccy) | ✅ | wallet.php:241-372 | reuse |
| Agent **wallet-only** at payment (enforced) | ✅ | wallet.php ~614; payment-gateway.php:126-132 | reuse |
| Credit line (users.credit_limits) + reminder cron | ✅ | wallet.php:290-298; creditsRemindersRoutes.php | reuse |
| **DVA create (NUBAN), idempotent** | ✅ | wallet.php:1121-1233 (`paystack_dva_activate`) | unblock for agents |
| **DVA webhook → wallet credit (HMAC, idempotent)** | ✅ | paymentPaystackRoutes.php:141-217 | reuse unchanged |
| Tier promote on any top-up | ✅ | wallet.php:886-889 | reuse |
| "Book for a passenger" engine (LIVE/MANUAL) | ✅ | booking_manager.php; bookingManagerRoutes.php | reuse; swap settle |
| Safe settle primitive (pay+issue, idempotent) | ✅ | booking_manager.php:146 | reuse for MANUAL |
| Supplier issue loopback helper | ✅ | booking_manager.php:88 (`bm_fire_supplier_issue`) | reuse |
| Cancel / void / refund / resend (admin) | ✅ | bookingsRoutes.php:389-523 | scope to agent |
| `wallet_refund` (idempotent) + gateway refund | ✅ | wallet.php:970; payment-gateway.php:~2260 | reuse |
| Per-entity branded docs override | ✅ | wl_email_brand supplier_site_host.php:340-368; applied functions.php:3254-3264 (STAYS only), notify.php:260-271 | generalise to agents + all modules |
| 9 PDF doc templates (invoice=ticket/voucher) | ✅ | views/notifications/emails/<module>/booking_pdf.php | add colour + agent brand |
| Multi-service cart (tours/visa/esim) | ✅ | cartRoutes.php | reuse for trips |
| GIA trip composer + ai_trip unified booking | ✅ | ai/giaRoutes.php tools; modules/ai_trip/issue.php | reuse pipeline |
| NL cross-module search | ✅ | ai/aiSearchService.php:43 | reuse for trip builder |
| Supplier multi-tenant RBAC (invite/roles/can) | ✅ | functions.php:440-592; supplierStaffRoutes.php | clone for agents |
| Supplier portal shell + onboarding + branded site | ✅ | supplier/dashboard.php, sidebar.php, _onboarding.php, supplier_sitebuilder.php | clone for agents |
| Reports: per-agent sales/commission queries | ✅ | reportsRoutes.php:310-455 | scope to /agent, add export |
| **Agent client book / CRM** | ❌ | none (no clients/contacts table) | BUILD |
| **Saved trip plan / client proposal** | ❌ | none (cart/GIA are transient) | BUILD |
| **Agent self-markup (client price layer)** | ❌ | none — agent pays full price_markup | BUILD (decision §5) |
| **bookings.agency_id / booked_by_staff_id** | ❌ | not in schema | BUILD (needed for scoping + per-staff reports) |
| **Agent-scoped booking actions + ownership guard** | ❌ | admin-only today | BUILD |

**Net:** the engines (money, booking, documents, search, trip-compose, RBAC,
portal shell) all exist. The ERP is: **a dedicated portal shell + agent-scoped
wrappers + 4 genuinely new pieces** (CRM, saved trip plans, self-markup layer,
agency_id/staff_id attribution columns).

---

# §A — EVERY EXPLICIT REQUIREMENT

### A1. Seamless onboarding
**Today:** `CHECK_AGENT()` (`functions.php:5965-5996`) redirects agents with an
incomplete `agencies` row to `/agency-details` — a blunt gate, no wizard; empty
agency auto-created; agents **auto-activate on email-verify with no approval**
(`emailVerificationRoutes.php:44`) though `signup.php` promises approval.
**BUILD** a `/agent/onboarding` wizard mirroring `supplier/_onboarding.php`
(data-driven checklist, computed each load): (1) agency profile → (2) branding
logo+colours → (3) fund wallet / activate DVA → (4) invite staff → (5) first
booking. Replace the blunt `CHECK_AGENT` redirect with "resume setup."
**Decision §5.1:** add admin approval gate? (recommend yes.)

### A2. Booking Centre — book for passengers (the NEW dashboard core)
**Reuse** the admin Booking Manager engine (`booking_manager.php`,
`bookingManagerRoutes.php`): LIVE mode (search real supplier → draft → settle →
issue loopback) and MANUAL mode (type PNR/hotel → confirmed branded voucher);
passenger details captured as `travellers` JSON
(`bookingManagerRoutes.php:205-237`).
**BUILD `/agent/booking-centre`** (new dashboard screens, one per service, reusing
each service's existing search via `aiSearchService`/listing routes + the BM
passenger form):
- Attribution: `bookings.user_id = agency owner`, `booking_channel='agent'`,
  **new `bookings.agency_id` + `booked_by_staff_id`** (which staff booked).
- **Settle = wallet only:** `wallet_spend(..., allow_credit_line=true,
  idempotency_key='AGT-BOOK-{invoice}-{service}')` — the exact pattern already in
  `agent_api_charge_wallet` (`functions.php:7019-7024`). For LIVE, then fire
  `bm_fire_supplier_issue()` (`booking_manager.php:88`). Server re-price via
  `MARKUP()` (never trust client price).
- **Fix** the two verified agent-core bugs here: `cartRoutes.php:470`
  (`agent_earning='0'`) and `userDashboardRoutes.php:60-63` (earnings summed over
  unpaid bookings) — the new dashboard computes/paid-only reports correctly.

### A3. Pay only from wallet
Already enforced server-side for agents (`wallet.php ~614`,
`payment-gateway.php:126-132`). The Booking Centre settles via `wallet_spend`;
no gateway path is offered to agents. Credit line (`users.credit_limits`) remains
an admin-granted overdraw.

### A4. Wallet funded by a generated DVA
**Today the whole rail exists and is role-agnostic** — `paystack_dva_activate()`
(`wallet.php:1121`) mints the Paystack customer + NUBAN; the webhook
(`paymentPaystackRoutes.php:141-217`) verifies HMAC-SHA512, matches
`paystack_customer_code`, credits via the idempotent spine
(`PSKDVA-{reference}` + UNIQUE), and promotes tier. **The ONLY blocker is a
route gate** rejecting agents: `userDashboardRoutes.php:331-336` ("Virtual
accounts are for customer wallets"). **BUILD:** a `/agent/wallet` screen (balance,
NUBAN, credit line, statement from `wallet_ledger`) + enable agent DVA by a
parallel `/agent/wallet/dva/activate` calling the same role-agnostic function
(NGN-only remains). Webhook needs **zero** changes. Keep the manual
bank-transfer deposit + admin approval (`api/users/depositRoutes.php`,
`ajaxRoutes.php:1171-1197`) as a fallback.

### A5. Staff management + RBAC (default + custom roles) — clone supplier RBAC
**Recommendation (your deferred question):** clone the **supplier multi-tenant
RBAC** — proven: `owner_user_id` scoping + cross-tenant guards
(`functions.php:440-500`), token invite flow with account-takeover guards
(`supplierStaffRoutes.php:86-163`), `{module:{action}}` permission matrix, custom
roles (`is_system` flag), `supplier_acting_context()`/`supplier_can()`
(`functions.php:505-592`). NOT the support RBAC (single-tenant, chat-oriented);
NOT `users.created_by` (unused).
**BUILD** sister tables + helpers:
- `agent_roles` (owner_user_id, name, permissions JSON, is_system) — seed
  **Owner(*) / Manager / Booking Agent / Accountant** as `is_system=1`; owner adds
  custom roles.
- `agent_staff` (owner_user_id, staff_user_id, role_id, invite token/expiry,
  status, created_by) — reuse the invite/accept routes verbatim, renamed.
- `agent_acting_context()` / `agent_can($db,$module,$action)` / `AGENT_OR_STAFF_AUTH`
  — parallel copies of the supplier spine.
- Capability modules: bookings, clients, trips, wallet, reports, branding, staff,
  roles, api. Every `/agent/*` route gated by `agent_can(...)`.

### A6. Upload logo + brand colours
**Today:** `agencies.logo` exists + is uploaded (`ajaxRoutes.php:1283-1323`) but
shown only in the profile; no colour columns. **BUILD:** `agencies.brand_primary`
+ `agencies.brand_accent` (hex, validated; via `ensure*` + install/db.sql); a
`/agent/branding` screen (logo upload via `secureUploadCheck()`, 2 colour pickers,
live preview). Optional later: full theme JSON like
`supplier_sitebuilder.php:21-65` if the agent wants a branded micro-site.

### A7. ALL tickets + invoices in the agent's brand + contact
**Today:** `GENERATE_BOOKING_PDF()` (`functions.php:3252`) renders **9 module PDF templates (of 11 services — esim + insurance lack one)
templates** (the invoice PDF *is* the ticket/voucher — flights PDF carries
segments/PNR/seats/baggage; stays PDF carries the hotel voucher + cancellation
policy). A per-entity branding override (`wl_email_brand()`,
`supplier_site_host.php:340-368`) swaps logo/name/email/phone with **zero template
change — but only for STAYS** (`functions.php:3299-3308`); all other 8 modules +
emails are **hardcoded to platform branding**.
**BUILD:**
- `agent_booking_brand($db,$booking)` (parallel to `wl_email_brand`) returning
  the agency's name/email/phone/logo + the 2 colours, when the booking's
  owner/staff is an agency.
- Call it in `GENERATE_BOOKING_PDF()` right after the stays hook (extend the
  condition to ALL modules) and in `notify.php:260-271` for emails.
- Extend the 9 PDF templates + email header/footer to honour `brand_primary/accent`
  (currently hardcoded hex).
- Result: the agent downloads/shares, per service, a **branded invoice + branded
  ticket/voucher + branded confirmation email** carrying their logo, colours,
  agency name, phone, email — client sees the agency, never GoGlobia, never the
  supplier (supplier identity already stripped from all docs — verified
  `invoice/summary.php`, `price_original` never rendered).
**Decision §5.4 (LOCKED): all 11 services** — brand the 9 existing PDFs + add a generic PDF for esim + insurance.

### A8. A complete ERP — see §B for the full module set.

### A9. Create trip plans
**Today:** multi-service **cart** (tours/visa/esim, `cartRoutes.php`), **GIA tool
pipeline** (search→select→compose→checkout→approve→book→pay, with HMAC-signed
price snapshots + `modules/ai_trip/issue.php` per-line issuance), and
`aiSearchService` NL search. **No saved/persistent itinerary or client proposal
exists** (verified — only transient `logs_bookings` drafts + `gia_checkouts`
snapshots).
**BUILD `/agent/trips`:** `agent_trip_plans` + line items (named, saved,
draft→quote→proposal→book). Compose via `aiSearchService` + the cart/GIA reprice
helpers (`aiTripRevalidatePackage`); freeze price with the GIA snapshot functions
(`gia_canonical_quote`/`gia_snapshot_hash`/`gia_verify_approval`); convert to
bookings via the `ai_trip` path; settle via wallet. Produce a **branded trip
proposal PDF** (§A7 path) the agent sends a client before payment, with a
client-approval link.

---

# §B — A COMPLETE ERP (the "and a lot of things")

1. **Portal shell + nav** (`/agent/*`, agent sidebar, dashboard) — §A0/§1.
2. **Agent CRM / client book** — NEW `agent_clients` (name, contact, passport,
   DOB, nationality, tags, notes); "book for existing client" pre-fills
   `travellers` JSON. No such table exists today.
3. **Bookings management** — agent-scoped list + per-booking actions (resend
   voucher, cancel, **refund-to-client / refund-to-wallet**, re-issue, edit
   traveller) reusing the admin lifecycle toolkit (`bookingsRoutes.php:389-523`,
   `wallet_refund`, `bm_fire_supplier_issue`) behind `agent_can` + an **ownership
   guard** (booking's `agency_id` == acting owner). Note: post-issue date/pax
   **amend is not supported by suppliers** — documented as new-booking+refund.
4. **Reports & statements** — scope the proven admin queries
   (`reportsRoutes.php:310-455`) to the agency; wallet statement from
   `wallet_ledger`; **paid-only** earnings (fixes the dashboard bug); per-staff
   production (needs `booked_by_staff_id`); tier savings; loyalty ledger; credit
   statement; CSV + branded PDF export (reuse the admin CSV streamer
   `reportsRoutes.php:12-33`).
5. **Money/pricing clarity + agency self-markup** — see §4 (the money model) and
   decision §5.2.
6. **Comms** — client booking confirmation emails FROM the agency brand (§A7
   email path); low-balance + credit-due alerts (cron exists); staff invite emails.
7. **Agent API** — already built (`functions.php:6741-7232`, `agentApiRoutes.php`);
   surface keys + per-service fees + docs inside the portal.
8. **Security/audit** — agent action audit log (mirror `supplier_rbac_ext.php`
   audit), step-up re-auth for wallet/staff actions, CSRF on all POST,
   rate-limit booking endpoints.
9. **Settings/toggles** — `agent_registration` (exists) + new
   `agent_portal_enabled`, `agent_dva_enabled`, `agent_self_markup_enabled`.
10. **Tier & loyalty pages** — lift the dashboard widgets to dedicated pages with
    history (data already computed in `userDashboardRoutes.php:89-126`).
11. **Credit management** — surface assigned/used/due (admin logic in `user.php`
    + `creditsRemindersRoutes.php`) to the agent read-only.
12. **Optional branded micro-site** — reuse `supplier_sitebuilder` theme system
    if agents want a public branded booking page (later phase).

---

## 4. The money model — VERIFIED, with a correction to v1

Exact waterfall when an agent books (`functions.php:4565-4774`, `6958-7024`;
`groups.php:468-486`):

```
supplier net           = price_original            (hidden from everyone)
  + platform B2B markup (modules.markup_b2b − tier discount)   [MARKUP()]
  = price_markup        = WHAT THE AGENT PAYS US (debited from wallet)
agent_earning           = the PLATFORM's margin on the agent (= the B2B markup)
```

**Correction to v1:** `agent_earning` is the **platform's** cut on the agent, NOT
the agent's own profit. **There is no agent client-price layer in the system
today** — the agent pays `price_markup` and must mark up their client entirely
outside the platform. For a true B2B ERP this is the biggest gap.

**Agency self-markup (BUILD, if approved):** add
`users.agent_customer_markup` (or per-agency per-service table) + booking columns
`agent_client_price` + `agent_client_margin`. Cleanest insertion point: after
`MARKUP()` computes the agent's cost, layer the agency's own markup to produce the
**client price shown on the agency-branded invoice**; the agent still pays us
`price_markup` and keeps the difference. Agent dashboard then shows, per booking:
*"You pay: X · Your client price: Y · Your margin: Y−X."* Supplier net stays
hidden.

---

## 5. Data model — new tables & columns (all via idempotent `ensure*` + db.sql)

| Table / column | Purpose | Pattern source |
|---|---|---|
| `agent_roles` | per-agency roles (default+custom) | supplier_roles |
| `agent_staff` | invited staff sub-users, owner-scoped | supplier_staff + supplierStaffRoutes |
| `agent_clients` | CRM / client book | NEW |
| `agent_trip_plans` + `agent_trip_items` | saved itinerary / proposal | cart + GIA composer shape |
| `agencies.brand_primary`, `agencies.brand_accent` | branding colours | supplier theme |
| `bookings.agency_id`, `bookings.booked_by_staff_id` | attribution + per-staff reports | NEW |
| `users.agent_customer_markup` + `bookings.agent_client_price`/`agent_client_margin` | self-markup (if approved) | §4 |
| `agent_audit` (opt) | action log | supplier_rbac_ext audit |

---

## 6. Phased build plan (each phase = own PR; owner reviews/merges/deploys)

- **P0 — Enable agent DVA** (tiny): unblock the route gate so agents fund via NUBAN.
- **P1 — Portal shell + dashboard + onboarding wizard + branding** (logo+colours).
- **P2 — Staff RBAC** (`agent_roles`/`agent_staff`/`agent_can`, invite/accept, gating).
- **P3 — Booking Centre** (new dashboard, per-service, wallet-settled, attribution
  columns; fix cart/dashboard bugs).
- **P4 — Branded documents** (`agent_booking_brand` across all 11 modules + emails +
  colour-aware templates).
- **P5 — Agent CRM + Trip plans + client proposal PDF.**
- **P6 — Bookings management (cancel/refund/reissue/resend) + Reports/statements.**
- **P7 — Comms, audit, settings toggles, tier/loyalty/credit pages.**
- **P8 (optional) — Agency self-markup layer** (if approved) + branded micro-site.

---

## 7. THE FULL 360° ERP — complete module catalogue (nothing skipped)

Every module below is a screen (or set) inside `/agent/*`, gated by `agent_can`,
scoped to the acting agency, reusing the verified engine named. "Piped to our
system" means the agent's action flows through our existing booking/money/supplier
rails — the agent never leaves our platform and never sees suppliers or our net.

### M1 — Onboarding & account (§A1)
Signup (`status='pending'`) → email verify → **admin approval** → login →
`/agent/onboarding` wizard (agency profile → branding → fund wallet/DVA → invite
staff → first booking). Resume-setup banner replaces the blunt `CHECK_AGENT`
redirect. Admin approval screen clones `admin/suppliers` (approve/reject + reason).

### M2 — Agent dashboard (home) (§A0)
Wallet balance + DVA NUBAN + tier ribbon; onboarding checklist (until complete);
KPI cards (today's bookings, wallet, credit available/used, earnings paid-only,
staff online); module launcher grid; recent bookings + alerts. Staff see a
role-scoped subset. Mirrors `supplier/dashboard.php`.

### M3 — Booking Centre (§A2) — book any of the 11 services for a passenger
One screen per service — flights, stays, tours, cars, umrah, visa, bus, rail,
ferries, esim, insurance — each reusing that service's existing search + the Booking
Manager passenger form (`travellers` JSON). LIVE (search→draft→wallet settle→
`bm_fire_supplier_issue`) and MANUAL (type PNR/hotel→branded voucher). Attribution
`agency_id` + `booked_by_staff_id`. Server re-price via `MARKUP()` + agency
self-markup (§4). "Book for existing client" pulls from the CRM (M6).

### M4 — Wallet & funding (§A3/§A4)
Balance (per currency), **DVA NUBAN** (activate + show bank/account), credit line
(available/used/due), full statement from `wallet_ledger`, top-up via DVA transfer
(auto-credit webhook) or manual-deposit fallback. Agents pay bookings ONLY from
here.

### M5 — Staff & RBAC (§A5)
`agent_staff` + `agent_roles` (default Owner/Manager/Booking-Agent/Accountant +
custom). Invite (email, token, 7-day) / accept / revoke; per-role `{module:action}`
matrix; `agent_can` gates every screen. Owner-only.

### M6 — Clients / CRM (NEW)
`agent_clients`: name, contacts, passport/DOB/nationality, tags, notes, booking
history. "Book for this client" pre-fills `travellers`. Import/export. The agency's
own customer book — does not exist today.

### M7 — Trip plans & client proposals (§A9, NEW)
`agent_trip_plans` + items: named, saved, multi-service itinerary
(draft→quote→proposal→book) built on `aiSearchService` + cart/GIA reprice +
HMAC price snapshot; **branded proposal PDF** the agent sends a client with an
approval link; convert-to-booking via the `ai_trip` path + wallet settle.

### M8 — Bookings management (§B3)
Agent-scoped list + per-booking actions behind `agent_can` + ownership guard:
view, download branded docs, resend voucher, cancel, **refund** (to client
gateway or to agency wallet via `wallet_refund`), re-issue, edit traveller. Reuses
the admin lifecycle toolkit (`bookingsRoutes.php:389-523`). Post-issue date/pax
amend = documented as new-booking+refund (suppliers don't support it).

### M9 — Branded documents (§A7) — ALL 11 services
`agent_booking_brand()` generalises `wl_email_brand` to every module PDF + emails;
templates honour `brand_primary/accent` + logo + agency contact. The agent shares,
per service, a branded invoice + branded ticket/voucher + branded confirmation
email. Client sees the agency, never GoGlobia, never the supplier.

### M10 — Pricing & self-markup (§4)
Per-agency (optionally per-service) client markup over our B2B cost. Dashboard +
each booking show "You pay X · Client price Y · Your margin Y−X". New cols
`users.agent_customer_markup`, `bookings.agent_client_price`, `agent_client_margin`.

### M11 — Reports & statements (§B4)
Agency-scoped versions of the proven admin queries (`reportsRoutes.php:310-455`):
sales, earnings (paid-only), per-staff production (via `booked_by_staff_id`),
wallet statement, credit statement, tier savings, loyalty ledger. CSV + branded
PDF export (reuse `reportsRoutes.php:12-33`).

### M12 — Tier, loyalty & credit (§B10/§B11)
Dedicated pages lifting the dashboard widgets: tier progress + history, loyalty
earn/redeem/convert ledger, credit assigned/used/due (read-only; admin grants).

### M13 — Communications (§B6)
Client booking confirmations FROM the agency brand; low-balance + credit-due
alerts (cron exists); staff-invite + proposal emails. All via the §A7 branded
email path.

### M14 — Agent API (already built, §B7)
Surface keys + per-service fees + docs inside the portal
(`functions.php:6741-7232`, `agentApiRoutes.php`). Same wallet/pricing rails.

### M15 — Security, audit & settings (§B8/§B9)
`agent_audit` action log (mirror `supplier_rbac_ext` audit); step-up re-auth for
wallet/staff actions; CSRF on all POST; rate-limited booking endpoints. Admin
toggles: `agent_registration` (exists) + `agent_portal_enabled`,
`agent_dva_enabled`, `agent_self_markup_enabled`.

### M16 — (optional, later) Branded micro-site
Reuse `supplier_sitebuilder` theme engine for a public branded booking page.

---

## 8. BUILD ORDER — LOCKED (full 360°, no steps skipped)

Each phase = its own PR; owner reviews/merges/deploys. I build sequentially,
verify each over real HTTP where possible, and report before moving on.

- **P0 — Enable agent DVA** (unblock `userDashboardRoutes.php:331-336`; wallet
  rail already role-agnostic). Smallest, highest-value first.
- **P1 — Portal shell + dashboard (M2) + onboarding wizard + admin approval (M1)
  + branding logo+colours (M9 storage).**
- **P2 — Staff & RBAC (M5)** — `agent_roles`/`agent_staff`/`agent_can`/
  `AGENT_OR_STAFF_AUTH`, invite/accept/revoke.
- **P3 — Booking Centre (M3)** across all 11 services + `agency_id`/
  `booked_by_staff_id`; wallet-settled; fix cart/dashboard earning bugs.
- **P4 — Branded documents (M9)** across all 11 modules (incl. new generic PDF for esim + insurance) + emails + colour templates.
- **P5 — Pricing & self-markup (M10)** — the agent client-price layer.
- **P6 — Clients/CRM (M6) + Trip plans & proposals (M7).**
- **P7 — Bookings management (M8) + Reports/statements (M11) + Tier/loyalty/credit
  (M12).**
- **P8 — Comms (M13) + Agent API surfacing (M14) + Security/audit/settings (M15).**
- **P9 (optional) — Branded micro-site (M16).**

## 9. Next action
Spec LOCKED. Starting **P0** (enable agent DVA) on branch `feat/agent-erp`, then
proceeding phase-by-phase, verifying and reporting at each PR. Owner deploys.

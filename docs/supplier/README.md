# Supplier Platform — Research & Specification Suite

> **Status:** research in progress. This suite is the design-of-record for turning
> goglobia's dormant `supplier` role into a full supplier marketplace **and** — for
> stays/hotels — a complete hotel ERP. Every structural claim about the existing
> codebase is cited to `file:line`; industry claims are cited to sources.
>
> **Method (per owner):** exhaustive, autonomous, multi-phase. "Don't rush, don't
> guess, don't lie." Where the code doesn't support a claim, the doc says so.

---

## What already shipped (the starting point)

Self-service **supplier registration + admin approval** is live (commit `75f98a4`):

- Public `/supplier-signup` → creates `users.role='supplier'`, `status='pending'`.
- Login gate blocks pending/rejected suppliers with specific messaging; an approved
  supplier is redirected to `/supplier/dashboard` (guarded by `SUPPLIER_AUTH()`).
- Admin queue at `/admin/suppliers` → approve/reject (ADMIN_AUTH + CSRF + email).
- Toggle: the existing `settings.supplier_registration` flag.
- Schema: `users.status` widened to `active/inactive/pending/rejected`,
  `users.supplier_rejected_reason` added (`ensureSupplierSchema()` in
  `app/lib/functions.php`).

This suite specifies **everything that comes after approval**: what a supplier can
create, how it is approved per-listing, how many they may create (quotas), how they
get paid (payouts), and — for hotels — the full operational ERP.

---

## The documents

| Doc | Scope |
|---|---|
| [`00-supplier-platform.md`](00-supplier-platform.md) | Cross-service foundation: the supplier identity & data model, onboarding, **service selection at signup** (supplier declares which services they offer, visible to admin pre-approval), **per-listing approval** workflow, **quotas** (admin-raisable listing limits), supplier settings, notifications, and the **payout / withdrawal** system (options + tradeoffs; highest-risk). |
| [`01-stays-hotel-erp.md`](01-stays-hotel-erp.md) | **The big one — the Hospitality Operating System (360° ERP).** Reframed around **18 product domains**: Marketplace/Distribution · PMS/Front-Office · Guest Experience · Housekeeping · Engineering/Maintenance · F&B/POS · Events/Banquets(MICE) · Sales/Commercial-CRM · Revenue-Management · Finance(GL/AR/AP) · Procurement/Inventory · Workforce · Apartment/Owner-Management · Long-Stay/Tenancy · Property/Asset-Ops · Analytics/BI · Automation/AI · Platform/Integrations. §§3–8 detail the PMS spine (domains 1–6); §9b summarizes domains 7–18. |
| [`01a-erp-data-foundations.md`](01a-erp-data-foundations.md) | **The model-level decisions that must exist from day one** — org→brand→property→unit hierarchy, accounting GL + hospitality subledger, cashier/shift, the party model (guests/companies/agents/vendors/owners), property-owner + trust/client-money accounting, generic bookable resources, stores/stock, generalized permission→role→user RBAC, the workflow/event bus, platform-wide audit, documents/compliance/migration. These are expensive to bolt on after PMS code hardens, so they're specced now even though built later. |
| [`02-flights.md`](02-flights.md) | Flight supplier: what a flight supplier lists/manages (own-inventory flights), approval, pricing/commission, payout. |
| [`03-tours.md`](03-tours.md) | Tour/activity supplier: tours, itineraries, schedules, capacity, approval, payout. |
| [`04-cars.md`](04-cars.md) | Car-rental supplier: fleet, availability, locations, pricing, approval, payout. |
| [`05-other-services.md`](05-other-services.md) | Visa, eSIM, ferries, rail, bus, insurance — each as a supplier chapter at depth. |
| [`06-roadmap.md`](06-roadmap.md) | Cross-cutting phased build plan, dependency graph, and the open decisions the owner must make. |

> Owner directive: **spec everything to full depth; build/phase order chosen after
> the full map is visible.** `06-roadmap.md` proposes an order but does not assume it.

---

## Cross-cutting principles (apply to every service)

1. **Reuse the existing inventory tables.** Supplier-created listings live in the
   SAME tables the platform already books from (`stays`, `stays_rooms`,
   `stays_rooms_calendar`, `flights`, `tours`, `cars`), stamped with the supplier's
   `users.user_id` as owner — not a parallel schema. (Grounded in the booking-flow
   audit; see `00-supplier-platform.md` §Listing model.)
2. **Approval is per-listing, not just per-account.** A supplier is approved to
   *participate*; each property/listing (and material changes to price/availability
   policy) can require its own review. Modeled on the existing umrah group-review
   state machine (`app/lib/umrah/groups.php`).
3. **Money moves only through the spine.** Supplier earnings and payouts use the
   existing `wallets` / `wallet_ledger` / `money_transactions` spine
   (`app/lib/wallet.php`) with the same idempotency/locking discipline. Outbound
   money (bank payouts) is the highest-risk addition and is specified conservatively.
4. **Admin keeps ultimate control.** Every supplier capability has an admin override:
   approve/reject, raise/lower quotas, suspend, adjust commission, hold payouts.
5. **Progressive disclosure.** A supplier only sees the modules for the services they
   offer and are approved for. A hotel sees the PMS; a car-rental supplier does not.

---

## Industry baseline (why the feature list looks the way it does)

The feature scope is calibrated against what real platforms ship, so suppliers find
it familiar and complete:

- **OTA extranets** (Booking.com Extranet, Expedia Partner Central): rates & rate
  plans, availability calendar, restrictions, promotions, reservations, payout
  reconciliation, content/photos, onboarding with business + bank documents.
- **Hotel PMS** (Cloudbeds, Mews, Oracle OPERA): front desk/folio, housekeeping,
  maintenance work orders, night audit, reporting; OPERA adds events, revenue
  management, and an integrated POS.
- **Hotel POS / F&B**: menu management, table/order service, KDS, and **charge-to-room**
  via POS↔PMS integration (multiple outlets per property).
- **Channel manager**: a **pooled-inventory** hub with two-way sync that queues
  simultaneous bookings to eliminate overbooking across all channels — the technical
  answer to "walk-in + OTA + direct site must never double-sell a room."
- **Commission & payout norms**: agency model (10–15% commission) vs merchant model
  (net rate + markup); OTA commissions commonly 10–30%; **payout typically after the
  guest's stay/checkout**, with the platform holding funds as intermediary.
- **Custom domains / white-label**: tenant points a CNAME at a host we control + a
  verification record; automated ACME (Let's Encrypt) TLS per hostname.
- **Smart-lock SDKs**: FLEXIPASS (ASSA ABLOY Vingcard / dormakaba / SALTO mobile
  keys), TTLock (BLE/WiFi + REST + mobile SDK), Tuya (WiFi/Zigbee/BLE), SALTO KS —
  time-bound credentials (one-time/auto-expiring keys) bound to a reservation.

Sources are cited inline in each chapter.

---

*Last updated during active research. See each chapter's own status banner.*

# 01a — ERP Data Foundations (the model-level decisions that must exist from day one)

> **Status:** architecture spec. Companion to [`01-stays-hotel-erp.md`](01-stays-hotel-erp.md)
> (the 18 domains) and the roadmap. **Why this doc exists:** a 360° Hospitality
> Operating System is not "PMS + more features." Several pieces are *data-model
> level* — org hierarchy, an accounting ledger, property owners, corporate accounts,
> generic bookable resources, stores/stock, generalized RBAC, a workflow/event bus,
> and a platform-wide audit trail. **These are expensive to bolt on after PMS code
> hardens**, so they must be designed now even though they are built later. This doc
> captures the foundations; the domains in `01` build on them.
>
> **Canonical scope** = [`01b-hospitality-pms-catalogue.md`](01b-hospitality-pms-catalogue.md)
> (the 61 numbered modules). This doc is the shared **data model** those modules build
> on — foundations, not the module index.
>
> **Grounding note (honest):** this is forward architecture. It builds on what Phase 1
> actually shipped in code (verified against commits), and does NOT claim any of the
> foundations below are built — they are not. Phase 1 = supplier onboarding/quota,
> owner-scoped property CRUD, supplier-defined roles + property scope, staff
> invitations, real inventory + atomic holds, per-listing approval, branded-site
> foundation. Everything in this doc is net-new.

---

## 0. The conceptual shift (the single most important change)

Stop modelling:  **Supplier → Hotel Listing.**

Model instead:  **Hospitality Organization → Property/Portfolio Operating System →
Distribution (GoGlobia marketplace is ONE channel).**

The GoGlobia marketplace becomes one sales channel inside the property's operating
system — alongside the property's own direct site, walk-ins, phone, and (future)
external OTAs — all drawing on one pooled inventory and one ledger. Every foundation
below serves that reframing.

---

## 1. Organization → Unit hierarchy (affects every table's ownership key)

Phase 1 keys ownership on a single `users.user_id` string (the supplier = the owner).
That is correct for a one-property supplier but **cannot express a chain or a
multi-unit apartment operator**. Introduce an explicit hierarchy that every
operational row references:

```
org            (the hospitality organization / account; = today's supplier user, promoted)
└─ brand       (optional: a brand within the org)
   └─ property (a hotel, aparthotel, or a managed-apartment building; ~ today's `stays` row)
      └─ building / block   (optional, large properties)
         └─ unit            (a room OR an apartment — the sellable/occupiable thing)
```

- **`stays` becomes `property`-level**; `stays_rooms`/units hang beneath it.
- Every operational table (reservations, folios, inventory, stock, staff, ledger
  entries) carries an `org_id` + `property_id` (+ `unit_id` where relevant), not just
  the flat `user_id`. The current `stays.user_id` owner is the migration anchor:
  **a one-time backfill promotes each supplier to an `org`** and each `stays` row to a
  `property` under it (mirrors the inc-7 `listing_status` backfill pattern).
- **RBAC + data scoping** (below) resolve against this hierarchy: a regional manager
  sees a region's properties; a front-desk clerk sees one property.

> **Phase-1 seam to add soon (before more feature code):** add nullable `org_id` /
> `property_id` columns (or a resolver that derives them from `user_id`) so the later
> domains have a stable hierarchy key to attach to. Don't rebuild the 8 shipped
> increments — add the hierarchy keys and a backfill.

---

## 2. Accounting ledger + hospitality subledger (the ERP backbone)

The Phase-1 **guest folio is guest accounting, not hotel accounting.** A real ERP has
a double-entry General Ledger that the operational subledgers post into. Do NOT turn
the folio into the GL — bridge them.

```
Hospitality subledger (operational)            Accounting ledger (financial)
  folio / folio_items          ──posts──▶   journal_entries → ledger_lines
  pos_orders / payments                        chart_of_accounts (COA)
  purchase invoices (AP)                        AR subledger (companies/agents)
  owner statements                              AP subledger (vendors)
  cashier shifts                                cashbook / bank_accounts
```

Posting rules (illustrative, must be data-driven, not hard-coded):
```
Room charge     → Folio → DR Guest AR / CR Room Revenue
Restaurant sale → POS → Folio → CR F&B Revenue
VAT             → CR Tax Payable
Cash received   → DR Cash/Bank / CR Guest AR
Corporate stay  → DR Company AR (settled later via AR subledger)
Supplier (GoGlobia) payout → separate spine (wallet.php), NOT the property GL
```

**Foundational tables (built later, modelled now):** `chart_of_accounts`,
`journal_entries` + `journal_lines` (balanced, immutable once posted), `accounts` for
AR (companies/agents) and AP (vendors/owners), `fiscal_periods` (with close),
`bank_accounts`, `cashbook`. Everything carries `org_id`/`property_id` + currency
(see §8) + a `cost_centre`/department tag.

**Critical separation:** the property's own books (this GL) are **distinct** from
GoGlobia's supplier-settlement money spine (`app/lib/wallet.php` — `wallets`,
`wallet_ledger`, `money_transactions`). GoGlobia paying a supplier is one thing; a
property's guest/vendor/owner accounting is another. Keep two ledgers, clearly bounded.

---

## 3. Cashier & shift accounting (feeds night audit + GL)

Hotels operate cashiers and shifts, not just "payments." Model from day one because
night audit and the cashbook consume it:
`cashier_shifts` (user, station, opening float, open/close times),
`cash_movements` (received / paid-out / refund / safe-drop, each linked to a shift and
a folio/PO where relevant), with expected-vs-actual + over/short + supervisor approval.
Both front-desk and POS cashiers reconcile into the same model; night audit rolls them.

---

## 4. Parties: guests, companies, travel agents, vendors, owners (one party model)

The platform has many "who" entities. Model them as **typed parties** with
subtype-specific extensions, so AR/AP, CRM, and statements share one spine:

```
party (id, org_id, type: guest|company|agent|vendor|owner|employee, name, contacts…)
  ├─ guest_profile    (preferences, loyalty, stay history, 360 identity)
  ├─ company_account  (credit limit, terms, negotiated rates, authorized bookers, AR)
  ├─ agent_account    (IATA/ref, commission %, negotiated rates, production, AR)
  ├─ vendor_account   (services, contracts, SLA, docs, AP)
  └─ owner_account    (apartment/property owner — §5)
```

- **Corporate / government / agency billing** (huge in NG/Africa/ME): company stays
  route to a company master folio → AR subledger → invoice-later → ageing/collections.
- **Travel agents** are first-class (ties into GoGlobia's own B2B/agent ecosystem):
  profile, negotiated rate, commission payable + statement + settlement.
- This replaces the Phase-1 "guest profile = guest history" stub with a real two-domain
  CRM (guest CRM + commercial CRM).

---

## 5. Property owners + trust/client-money accounting (the apartment model)

Phase 1 treats apartments as hotels. That works for an aparthotel but NOT for a
**property manager** running units owned by *different* people. Distinguish:

```
operator (the GoGlobia supplier/org)  ≠  owner (the landlord of a unit)

property_manager → owners → units → management_agreement(commission, fees, terms)
```

- **Owner accounting:** per-owner statement = gross rental − OTA fees − platform
  commission − manager commission − cleaning/repairs/utilities/owner-expenses − taxes
  → owner payout + closing balance. PDF + Excel + owner portal.
- **Trust / client-money accounting:** money a manager collects on behalf of owners is
  **segregated**, not one pooled balance — guest money, platform money, manager money,
  owner money, security deposits are distinct buckets. The ledger architecture (§2)
  must support segregated client funds (three-way balancing) even where jurisdiction
  rules differ.
- **Four operating models share this core:** hotel/resort · aparthotel · short-let
  (multi-owner) · long-stay/corporate lease. A `unit` carries an operating mode.

---

## 6. Generic bookable resources (not just bedrooms)

Hotels sell more than rooms: conference halls, meeting/ballrooms, spa treatment rooms,
cabanas, parking bays, shuttle seats, coworking desks, equipment. Model a **generic
`resource` + `resource_booking`** with the same pooled-inventory + atomic-hold
discipline the room inventory already uses (`app/lib/stays_inventory.php`,
`stays_hold_create` = `SELECT … FOR UPDATE`). Rooms are one resource type; events,
spa, parking, etc. reuse the engine instead of each getting a bespoke system.

---

## 7. Stores / stock (distinct from room inventory)

"Inventory" today means **room availability**. ERP inventory means **stock**: kitchen,
bar, housekeeping, maintenance, minibar, central warehouse. Model:
`product` (SKU, UoM, category, reorder level, batch/expiry), `store`/`warehouse`,
`stock_movement` (receipt/issue/transfer/adjustment/count/waste, weighted-avg or FIFO),
`stock_valuation`. Then **integrate**: a POS sale consumes bar stock; a completed room
clean optionally consumes housekeeping supplies; a kitchen item consumes recipe
ingredients (recipe/BOM → food-cost %); minibar posting reduces stock + posts to folio.
This closes the loop that makes it an ERP rather than a PMS.

---

## 8. Multi-currency (designed across the ERP, not display-only)

Phase 1 converts currency for display. A 360 ERP needs currency *as a first-class
dimension*: property base currency, rate-plan currency, booking currency, payment
currency, supplier-payout currency, accounting base currency, FX rate, realized FX
gain/loss, owner-statement currency. Every money row stores amount + currency + the FX
rate used. This is foundational because retro-fitting currency into a single-currency
ledger is very costly.

---

## 9. Tax engine (configurable, not hard-coded per jurisdiction)

Extend the Phase-1 `tax_profile_id` direction into a real engine: inclusive/exclusive,
VAT, city tax, tourism levy, service charge, per-person vs per-night, %/fixed,
exemptions (company/diplomatic), per-outlet/service differences, tax-invoice numbering,
and fiscal/e-invoice connectors. **Jurisdiction rules live in configuration**, never
hard-coded in the PMS core (so NG/Africa/ME/EU all work).

---

## 10. Generalized RBAC: permission → role → user (replaces fixed roles)

Phase 1 ships supplier-defined roles with a permission matrix over a fixed module set
(`supplier_roles.permissions`, `supplier_role_modules()`) + all/selected property
scope — a solid start. Generalize it to a true permission catalogue so every ERP
domain plugs in:
```
permission (e.g. reservation.view, rate.edit, discount.approve, refund.issue,
            cashier.close, folio.adjust, owner_statement.publish, po.approve,
            journal.post, staff.manage)  →  role  →  user, scoped to org/property/unit
```
Plus an **approval-limits** layer (receptionist discount ≤5%, duty manager ≤15%, GM
unlimited; finance approves invoices; GM approves payments >threshold) delivered by a
single **Approval Workflow Engine**, not re-implemented per module.

---

## 11. Workflow / event bus (platform capability, a major differentiator)

Make domain events first-class so automation is declarative:
`reservation.created`, `reservation.vip`, `checkout.completed`, `room.cleaned`,
`stock.below_reorder`, `maintenance.overdue`, `invoice.overdue`, `guest.rated_low`,
`owner_statement.generated`. A **WHEN → IF → THEN** rule engine (eventually exposed to
supplier admins) drives confirmations, task creation, escalations, upsell triggers,
credit-control, review-recovery, owner notifications. The codebase already has a
`triggerWebhook()` seam (`app/lib/webhooks.php`) to build this on.

---

## 12. Platform-wide audit trail (immutable-ish)

Phase 1 audits supplier approvals/payouts (`logUserActivity`, `transaction_journey`).
Generalize to an append-only `audit_event` for every sensitive action across the ERP:
rate changed, reservation cancelled, folio adjusted, refund, room move, cashier close,
stock adjustment, PO approval, journal posted, owner statement changed, door opened,
permission changed — each with who / what / before / after / time / device-IP-session /
reason. This is both a governance and a trust/dispute requirement.

---

## 13. Documents, compliance, data migration (commercial foundations)

- **Document store** (`document` table): licences, insurance, fire/food certs,
  contracts (owner/vendor/company), guest IDs, warranties, invoices — with expiry
  alerts. Multi-property operators need this centrally.
- **Compliance**: jurisdiction-aware configurable checklists (licences, fire, hygiene,
  tourism levy, tax/e-invoice, guest-registration) — configuration, not hard-coded.
- **Data migration**: importers (CSV/Excel/API) for properties, units, future
  reservations, guests, rates, companies, opening AR, owners, employees, stock, opening
  balances. Commercially critical — a hotel on another PMS won't switch without it.

---

## Which foundations are urgent for Phase-1 schema (model-level seams)

Per the owner decision (spec now; add seams soon; don't rebuild shipped increments),
these are the ones that are cheap now and costly later, and should be reflected in the
Phase-1 schema **before** more feature code:

1. **Org/property/unit hierarchy keys** (§1) — add `org_id`/`property_id` (+ backfill
   from `user_id`) so later domains attach to a stable hierarchy.
2. **Party model** (§4) — so guests/companies/agents/vendors/owners aren't retrofitted.
3. **Owner linkage + segregated-funds-ready ledger** (§2, §5) — the apartment model and
   trust accounting depend on it.
4. **Generic resource + stock keys** (§6, §7) — reuse the hold engine; don't fork it.
5. **Generalized permission catalogue** (§10) — extend `supplier_roles` toward it.
6. **Event names + audit event** (§11, §12) — cheap to emit now, enables everything.

Everything else (full GL, procurement, MICE, RMS, BI, etc.) is later-phase build on
these foundations.

---

*Cross-reference: the 18 product domains that build on these foundations →
[`01-stays-hotel-erp.md`](01-stays-hotel-erp.md) §0 (domain index) and
[`06-roadmap.md`](06-roadmap.md) for phase ordering.*

# 01b — GoGlobia Hospitality PMS: canonical module catalogue (360°)

> **Status:** this is the **authoritative scope document** for the stays/hotel/
> apartment product. It is the target feature set — **not** a list of what is built.
> The other docs are deep-dives it cross-references:
> [`00-supplier-platform.md`](00-supplier-platform.md) (supplier identity/onboarding/
> approval/payouts), [`01-stays-hotel-erp.md`](01-stays-hotel-erp.md) (PMS spine +
> the 18-domain narrative), [`01a-erp-data-foundations.md`](01a-erp-data-foundations.md)
> (the data model the modules share), [`06-roadmap.md`](06-roadmap.md) (phase order).
>
> **Grounding (honest):** this is the proposed 360° target. For what has actually
> shipped in code (Phase 1 — supplier onboarding/quota, owner-scoped property CRUD,
> supplier roles + property scope, staff invitations, real inventory + atomic holds,
> per-listing approval, branded-site foundation), see the "shipped" note in `01` §1 and
> memory. Nearly everything below beyond that is net-new.

---

## 0. Product naming & positioning

**Product name: GoGlobia Hospitality PMS** — *"An all-in-one hospitality property
management & ERP system."* PMS is the term hotel/resort/serviced-apartment/vacation-
rental operators recognize; the positioning makes clear it goes beyond a traditional
PMS to include accounting, procurement, inventory, HR, POS, CRM, distribution, revenue
management and owner accounting.

Architecture (three layers):
- **Shared core** — organizations, users, finance, CRM, payments, automation, reporting.
- **Property-specific modules** — hotel PMS, short-let operations, owner management,
  leases, POS, events.
- **Distribution layer** — GoGlobia Marketplace · Direct Booking · External OTAs ·
  Corporate Channels. (The marketplace is **one channel**, not the sole purpose.)

> Build the 61 modules as **modular components sharing one organization, property,
> guest, booking, inventory, billing and accounting architecture — not 61 disconnected
> apps.** The shared architecture is specified in `01a`.

---

## 1. Accommodation operating models (one platform, many models)

When a supplier creates a property they select an **accommodation type**; the system
then presents the appropriate modules, terminology, workflows and defaults.

Supported models: **Hotels & Motels · Resorts & Lodges · Serviced Apartments ·
Vacation Rentals & Short-Lets · Hostels & Guesthouses · Long-Stay Apartments · Villas
& Holiday Homes · Property Management Companies.**

Examples of type-driven defaults:
- **Hotel** → front desk, room inventory, night audit.
- **Short-let** → units, turnovers, owners, deposits.
- **Long-stay** → leases, recurring rent, utilities.

> **Architectural rule (critical):** the property type must be **configurable, not
> permanently hard-coded at creation.** A hotel may also operate serviced apartments; a
> resort may have villas + restaurants + event venues; an apartment manager may run
> both nightly and monthly rentals. The system must allow **mixed property models** and
> **activation of additional modules** without rebuilding or duplicating the property's
> records. (This extends the org→brand→property→unit hierarchy in `01a` §1 with a
> per-property `accommodation_type` + a module-activation concept.)

---

## 2. The 61 modules (groups A–L)

Each module lists its sub-features. Deep architecture for the cross-cutting ones
(accounting ledger, party model, owners/trust, stock, RBAC, workflow, audit) is in
`01a`; the PMS-spine detail (domains 1–6) is in `01`.

### A. Core property & platform administration
- **01. Organization & supplier management** — supplier registration · business
  verification · supplier approval · multi-service registration · organization profiles
  · organization hierarchy · multiple properties · property-ownership assignment ·
  **supplier subscription plans** · listing quotas · supplier commission settings ·
  onboarding wizard. *(Deep-dive: `00`.)*
- **02. Property setup & configuration** — property creation · accommodation
  classification · property information · branding · buildings/blocks · floors/wings ·
  room/unit categories · physical room/unit registry · bed/occupancy config ·
  amenities/facilities · room features · house rules · check-in/out rules ·
  currencies/time-zones · tax profiles · departments/outlets · media library · property
  activation (draft→review→approved→live→suspended).
- **03. Multi-property / chain management** — corporate dashboard · brand-level control
  · regional management · centralized guest database · central reservations ·
  cross-property availability · central rate management · central corporate contracts ·
  central procurement · inter-property transfers · consolidated reporting ·
  property-specific permissions.

### B. Reservations, inventory & front office
- **04. Reservation management** — dashboard · calendar · room rack/tape chart ·
  create (front desk/phone/email/walk-in/online) · instant book · request-to-book ·
  modify · cancel · extend · shorten · transfer · split · merge/link · multi-room ·
  repeat · notes · source tracking · confirmation · waitlist · no-show processing.
- **05. Room & unit inventory** — room-type inventory · physical room inventory ·
  per-date availability · **atomic holds** · pooled inventory · manual blocks ·
  out-of-order · out-of-service · owner blocks · group allotments · stop-sell ·
  availability overrides · room mapping · inventory audit · forecasting. *(Shipped:
  per-date availability + atomic holds — `app/lib/stays_inventory.php`.)*
- **06. Rates, pricing & rate plans** — base rates · rate plans · daily calendar ·
  seasonal · weekday/weekend · derived · occupancy · length-of-stay · early-bird ·
  last-minute · dynamic rules · channel pricing · corporate negotiated · agent rates ·
  promo codes · packages · min/max stay · CTA/CTD · advance-booking windows · bulk
  update.
- **07. Front desk operations** — dashboard · check-in · check-out · walk-ins · room
  assignment · room changes · early check-in · late checkout · express check-in/out ·
  registration cards · identity verification · special requests · VIP handling ·
  messages/traces · wake-up calls · shift handover · room-status visibility ·
  arrival/departure manifests.
- **08. Group & block reservations** — group profiles · room blocks · allotment ·
  release/cutoff · rooming lists · bulk import · group rates · deposits · master folios
  · charge routing · group check-in · group invoices · pickup reports · group
  profitability.
- **09. Guest folio, billing & cashier** — folio · multiple folios · charge posting ·
  automatic room charges · charge routing · charge-to-room · split billing · payment
  collection · partial payments · preauthorizations · refunds · invoices · receipts ·
  credit/debit notes · **cashier shifts** · cash float · drawer ops · shift
  reconciliation · supervisor overrides · transaction audit.
- **10. Night audit & business-day close** — business-date management · dashboard ·
  automatic room postings · tax/service postings · no-show processing · cashier
  reconciliation · payment reconciliation · folio-exception detection · daily revenue
  report · occupancy snapshot · audit close · audit log + reopen controls.

### C. Distribution, sales & revenue growth
- **11. GoGlobia marketplace management** — listing · listing approval · content ·
  inventory sync · pricing · booking notifications · cancellation handling ·
  commission tracking · earnings dashboard · payout requests · payout reconciliation ·
  marketplace performance. *(Shipped: listing + per-listing approval + owner
  notifications; payouts net-new — see `00` §6.)*
- **12. Channel manager** — OTA connections · two-way availability sync · rate sync ·
  reservation import · cancellation sync · room mapping · rate-plan mapping · channel
  restrictions · channel-specific pricing · sync monitoring · retry/reconciliation ·
  channel performance · iCal · integration-partner management.
- **13. Direct booking engine & website builder** — property website · templates ·
  branding · custom pages · **custom domain** · free subdomain · live room search ·
  checkout · guest payments · multilingual · multi-currency display · SEO · promotion
  banners · reviews/testimonials · analytics integration · abandoned-booking recovery ·
  direct-booking incentives · mobile optimization. *(Shipped: branded-site foundation +
  custom-domain request + preview — `01` §7; full engine + TLS net-new.)*
- **14. Revenue management & forecasting** — dashboard · ADR · RevPAR · TRevPAR ·
  GOPPAR · occupancy forecasts · demand forecasting · booking pace · lead-time ·
  competitor-rate monitoring · dynamic-pricing suggestions · automated yield rules ·
  LOS optimization · overbooking controls · revenue budgets · channel profitability.
- **15. Sales & corporate account management** — leads · pipeline · company accounts ·
  government/institution accounts · travel-agent accounts · corporate rate agreements ·
  credit limits · proposals/quotations · contracts · company billing · account managers
  · sales activities · corporate booking portal · agent commissions · account
  performance.
- **16. Events, meetings & banquets** — venue inventory · availability calendar ·
  enquiries · quotations · contracts · packages · **BEOs** · catering menus · seating ·
  equipment · staffing · event room blocks · deposits · event billing · event calendar
  · event profitability.
- **17. Packages & ancillary revenue** — package builder · meal plans · airport
  transfers · early/late check · room upgrades · extra beds/cots · spa packages · tours
  & activities · car rentals · **flight & travel cross-sell (GoGlobia verticals)** ·
  special-occasion packages · ancillary fulfillment · upsell performance.

### D. Guest relationship & experience
- **18. Guest CRM** — unified profiles · demographics · stay history · preferences ·
  VIP classification · segmentation · tags · communication history · complaints history
  · consent management · duplicate detection · guest lifetime value.
- **19. Unified guest communication** — central inbox · email · WhatsApp · SMS ·
  website chat · OTA messaging · conversation assignment · templates · automated
  messaging · internal notes · translation assistance · response tracking · AI reply
  assistance (governed).
- **20. Digital guest journey** — pre-arrival portal · online registration · digital
  identity capture · digital signatures · arrival-time collection · self-check-in ·
  **self-service kiosk** · welcome automation · in-stay portal · digital directory ·
  guest requests · online extras purchase · express checkout · post-stay follow-up.
- **21. Guest service & complaint management** — service-request tickets · categories ·
  department routing · staff assignment · priorities/SLAs · escalations · complaints ·
  service recovery · completion confirmation · satisfaction feedback.
- **22. Loyalty & membership** — membership · tiers · points earning · redemption ·
  member rates · member benefits · referral program · birthday/anniversary offers ·
  cross-property benefits · loyalty analytics.
- **23. Reputation & reviews** — guest surveys · internal ratings · public review
  collection · review aggregation · response management · sentiment analysis ·
  department performance · reputation dashboards · AI response suggestions.

### E. Housekeeping, engineering & facilities
- **24. Housekeeping management** — dashboard · automatic cleaning tasks · stayover ·
  departure · deep clean · turndown · attendant assignment · zones/workload ·
  checklists · supervisor inspection · room-status updates · DND/refused · room-setup
  requests · supply consumption · maintenance reporting · productivity reports.
- **25. Laundry & linen management** — linen catalogue · stock levels · issue/return ·
  dirty-linen collection · laundry processing · external vendors · guest laundry orders
  · lost/damaged linen · par-level management · laundry cost reporting.
- **26. Minibar management** — product catalogue · room assignments · consumption
  posting · replenishment · stock sync · inspection · revenue/shrinkage reports.
- **27. Maintenance & engineering** — dashboard · work orders · room/asset association
  · priorities · technician assignment · preventive maintenance · recurring inspections
  · out-of-order handling · repair materials · contractor management · labour/repair
  costing · warranty tracking · job evidence · SLAs · analytics.
- **28. Fixed assets & equipment** — asset register · categories · tagging (QR/NFC/
  serial) · locations · purchase info · depreciation records · warranty records ·
  transfers · repair history · disposal · asset reports.
- **29. Lost & found** — lost-item register · photos/descriptions · guest/reservation
  association · storage location · claim verification · return/shipping · disposal
  policies · audit history.

### F. Food, beverage & auxiliary services
- **30. Restaurant, bar & hotel POS** — multiple outlets · outlet config · menu
  categories · items · modifiers/variants · table/floor management · table reservations
  · waiter ordering · **KDS** · order lifecycle · split/merge · discounts/service
  charges · **charge-to-room** · standalone payments · room service · takeaway · voids/
  refunds · tips · POS shifts · outlet reporting.
- **31. Kitchen, recipe & food-cost management** — recipe catalogue · **BOM** · recipe
  costing · portion/yield · ingredient-inventory linkage · kitchen requisitions ·
  production planning · wastage/spoilage · actual-vs-theoretical · menu profitability ·
  food/beverage cost %.
- **32. Spa, wellness & recreation** — service catalogue · facility/resource calendar ·
  therapist/staff schedules · appointment booking · package booking · memberships ·
  service POS · charge-to-room · product consumption · commission tracking · reporting.
- **33. Other hotel services** — airport shuttle · concierge · parking · equipment
  rental · coworking/day-use · pool/beach cabanas · kids' activities · **generic
  bookable resources** · service fulfillment.

### G. Finance, accounting & payments
- **34. General ledger & financial accounting** — chart of accounts · GL · journal
  entries · double-entry · accounting periods · revenue recognition · department
  accounting · cost centres · accruals/prepayments · trial balance · P&L · balance
  sheet · cash-flow statement · budget management · budget-vs-actual · period closing ·
  multi-currency accounting · audit trail. *(Architecture: `01a` §2.)*
- **35. Accounts receivable** — customer accounts · credit facilities · credit limits ·
  invoice posting · receivables ledger · customer statements · AR ageing · payment
  allocation · collections · credit notes/write-offs · AR reporting.
- **36. Accounts payable** — vendor accounts · supplier invoices · invoice approval ·
  AP ledger · payment scheduling · partial payments · supplier credit notes · AP ageing
  · vendor statements · AP reports.
- **37. Cash, bank & treasury** — cashbooks · bank accounts · bank-transaction imports
  · bank reconciliation · cash transfers · petty cash · cash advances · payment
  approvals · cash-position reporting · cash-flow forecasts.
- **38. Tax & fiscal management** — tax configuration · VAT/GST · tourism/city taxes ·
  service charges · withholding taxes · inclusive/exclusive pricing · exemptions · tax
  invoices · tax reports · e-invoicing/fiscal integration. *(Config-driven, `01a` §9.)*
- **39. Payment processing & deposits** — gateways · cash/card/bank · payment links ·
  reservation deposits · installments · card preauthorizations · **security deposits**
  (hold/deduct/release) · refunds · chargeback management · multi-currency settlement ·
  payment reconciliation · payment audit trail.
- **40. GoGlobia supplier earnings & settlement** — commission calculation · earnings
  ledger · pending balances · available balances · payout-destination verification ·
  withdrawal requests · admin payout approval · automated payout schedules · refund/
  chargeback deductions · payout history/statements · reconciliation/exception handling
  · marketplace-fee reporting. *(Architecture + 7 outbound-money guards: `00` §6.)*

### H. Procurement, stores & inventory
- **41. Procurement management** — vendor directory · purchase requisitions ·
  requisition approvals · RFQs · quotation comparison · **purchase orders** · PO
  approvals · goods-received notes (GRN) · partial deliveries · purchase returns ·
  **three-way matching** · procurement contracts · budget control · analytics.
- **42. Stores & inventory management** — product/SKU catalogue · categories · units of
  measure · multiple stores · opening stock · receipts · issues · inter-store transfers
  · adjustments · counts · reorder levels · low-stock alerts · batch/expiry · waste/
  damage · valuation (FIFO/weighted-avg) · consumption reports · variance reports ·
  barcode/QR scanning. *(Architecture: `01a` §7; integrates POS/housekeeping/kitchen.)*
- **43. Vendor & contractor management** — registration · verification · categories ·
  contracts · price agreements · job assignment · invoices · performance · compliance
  reminders.

### I. Human resources, staff & workflows
- **44. Human resources & workforce** — employee records · departments/positions ·
  contracts · shift scheduling · attendance · biometric/QR attendance · leave ·
  overtime · timesheets · task assignment · performance KPIs · training records ·
  payroll integration/export · disciplinary/incident records · employee self-service.
- **45. Users, roles & permissions** — user accounts · **custom roles** · granular
  permissions · property-scoped access · department-scoped access · approval limits ·
  MFA · session/device controls · sensitive-action reauthentication · staff audit logs.
  *(Shipped: supplier custom roles + property scope + invitations — `01` §9b d8; the
  generalized permission→role→user catalogue is `01a` §10.)*
- **46. Task & workflow management** — central task board · templates · recurring tasks
  · event-triggered tasks · assignment engine · deadlines/priorities · checklists ·
  escalation rules · approval workflows · **workflow automation builder (WHEN/IF/THEN)**
  · workflow history · task analytics. *(Event bus: `01a` §11.)*

### J. Apartment, short-let & property-owner management
- **47. Apartment & vacation-rental management** — apartment creation · multi-unit
  buildings · individual unit listings · unit-type listings · unit amenities · unit
  availability · nightly/weekly/monthly pricing · cleaning fees · extra-guest/pet fees ·
  **security deposits** · guest verification · self-check-in instructions · turnover
  management · damage reporting · apartment profitability · short-let channel
  distribution.
- **48. Property owner & landlord management** — owner registration · verification ·
  ownership mapping · co-ownership · management agreements · management commissions ·
  owner-usage reservations · owner expenses · owner revenue allocation · **owner
  statements** · owner balances · owner payouts · **owner portal** · owner documents ·
  owner communications · owner portfolio reports. *(Architecture: `01a` §5.)*
- **49. Lease & long-stay management** — tenant profiles · lease creation · renewal ·
  **recurring rent billing** · rent schedules · rent escalation · tenant deposits · rent
  arrears · late-payment charges · utility billing · move-in inspections · move-out
  inspections · tenant maintenance requests · lease termination · tenant statements ·
  hybrid rental mode.
- **50. Trust & client-money accounting** — client-money ledgers · owner liabilities ·
  guest deposits/bonds · management-fee postings · owner expense deductions · owner
  payout reconciliation · trust reconciliation · client-fund audit reports.
  *(Segregated-funds architecture: `01a` §5.)*
- **51. Utilities & property service charges** — utility accounts · meter registry ·
  meter readings · consumption calculation · utility rates · utility billing · service
  charges · expense allocation · utility analytics.

### K. Security, smart property & compliance
- **52. Smart locks & access control** — lock registration · room-lock mapping ·
  PIN/mobile credentials · reservation-linked access · automatic expiration · credential
  revocation · staff access · access logs · gateway health · emergency access · device
  inventory/sales · provider adapters. *(Vision + provider abstraction: `01` §8.)*
- **53. Smart building & IoT** — smart thermostats · occupancy sensors · energy controls
  · leak detectors · door/window sensors · smart lighting · parking/gate control ·
  device monitoring · IoT automation rules.
- **54. Safety, security & incident management** — incident reports · incident evidence
  · severity classification · security shift log · **visitor management** · emergency
  procedures · fire/safety inspections · damage claims · escalation and closure.
- **55. Document & compliance management** — document repository · property licenses ·
  insurance records · supplier contracts · employee compliance docs · guest
  documentation · compliance checklists · expiry reminders · data-retention rules ·
  compliance reporting. *(Config-driven by jurisdiction: `01a` §13.)*

### L. Analytics, AI & platform technology
- **56. Dashboards & business intelligence** — executive · property · portfolio
  dashboards · reservation · occupancy · revenue · channel analytics · department P&L ·
  F&B · stock · housekeeping · engineering · workforce · guest analytics ·
  owner/apartment reports · financial reporting · scheduled reports · custom report
  builder · data export (Excel/CSV/PDF).
- **57. AI & intelligent automation** — AI hospitality assistant · AI guest concierge ·
  AI message drafting · AI revenue recommendations · occupancy prediction · cancellation
  prediction · upsell suggestions · guest segmentation · review sentiment · inventory
  demand forecasting · maintenance anomaly detection · financial anomaly alerts ·
  automated operational summaries · AI document extraction · AI governance.
- **58. Notifications & alerts** — in-app · email · SMS/WhatsApp · push · operational
  alerts · financial alerts · inventory alerts · approval alerts · system alerts ·
  custom notification rules.
- **59. API & integrations** — REST APIs · webhooks · API credential management ·
  integration marketplace · OTA · payment · accounting · messaging · POS/hardware ·
  lock/IoT · telephony/PBX · identity-verification integrations · integration logs ·
  retries/idempotency · developer docs. *(Platform layer: `01a` §11-13, `01` §9b d18.)*
- **60. Data migration & onboarding tools** — property setup wizard · room/unit import ·
  guest import · future-reservation import · rate/inventory import · company/contact
  import · owner/unit import · opening accounting balances · stock import · import
  validation · migration preview/rollback · go-live checklist · training/help centre.
- **61. Security & platform operations** — tenant isolation · RBAC · MFA · encryption ·
  audit trail · backup/disaster recovery · monitoring · data-privacy controls · API
  security · session security · business continuity · offline-capable workflows ·
  multi-language · multi-currency · time-zone awareness · configuration management.

---

## 3. Module activation by property type (defaults; admin can enable more)

The platform must **not** show every module to every property. Defaults by accommodation
type (✓ on by default · Optional = available, off by default):

| Module | Hotel | Short-let / apartment | Long-stay |
|---|---|---|---|
| Reservations (04) | ✓ | ✓ | ✓ |
| Availability/rates (05,06) | ✓ | ✓ | ✓ |
| Front desk (07) | ✓ | Optional | Optional |
| Night audit (10) | ✓ | Optional | Optional |
| Housekeeping (24) | ✓ | ✓ | Optional |
| Maintenance (27) | ✓ | ✓ | ✓ |
| Guest CRM (18) | ✓ | ✓ | ✓ |
| Guest messaging (19) | ✓ | ✓ | ✓ |
| Direct booking site (13) | ✓ | ✓ | Optional |
| Channel manager (12) | ✓ | ✓ | Optional |
| F&B / POS (30) | Optional | Optional | Optional |
| Group bookings (08) | ✓ | Optional | Optional |
| Events/banquets (16) | Optional | Optional | Optional |
| Finance/accounting (34–38) | ✓ | ✓ | ✓ |
| Procurement/stores (41,42) | ✓ | Optional | Optional |
| Workforce (44) | ✓ | Optional | Optional |
| Owner management (48) | Optional | ✓ | ✓ |
| Lease management (49) | Optional | Optional | ✓ |
| Recurring rent (49) | Optional | Optional | ✓ |
| Utility billing (51) | Optional | Optional | ✓ |
| Security deposits (39,47) | ✓ | ✓ | ✓ |
| Smart locks (52) | Optional | Optional | Optional |
| Analytics/BI (56) | ✓ | ✓ | ✓ |

> Activation is **configurable per property**, and a property's type/modules can change
> over time (a hotel adds serviced apartments; a short-let adds long-stay) **without
> rebuilding the property record** (§1 rule).

---

## 4. Supplier navigation (don't ship a 61-item menu)

Group the 61 modules into 14 functional areas in the supplier portal:

| Nav group | Covers (modules) |
|---|---|
| **Dashboard** | overview, KPIs, alerts, tasks |
| **Properties** | organizations, properties, rooms/units (01–03) |
| **Reservations & Front Desk** | bookings, room rack, groups, check-in/out, folios (04–10) |
| **Distribution & Revenue** | GoGlobia, channels, rates, direct sites, RMS (11–14) |
| **Guests & CRM** | profiles, messaging, portal, loyalty, requests, reviews (18–23) |
| **Property Operations** | housekeeping, laundry, maintenance, inspections, assets (24–29) |
| **POS & Services** | restaurants, kitchen, spa, extras, outlets (30–33) |
| **Sales & Events** | corporate accounts, agents, events, banquets (15,16) |
| **Finance & Accounting** | GL, AR, AP, payments, cashier, taxes, payouts (34–40) |
| **Procurement & Inventory** | POs, stores, stock, vendors (41–43) |
| **Owners & Tenancy** | owner statements, leases, utilities, deposits (47–51) |
| **Workforce** | employees, shifts, attendance, roles, tasks (44–46) |
| **Reports & Intelligence** | analytics, dashboards, AI, forecasting (56,57) |
| **Settings & Integrations** | setup, automations, security, APIs, devices (58–61) |

---

## 5. How this maps to the other docs (no duplication)

- **Supplier identity / onboarding / approval / payouts** → `00-supplier-platform.md`
  (modules 01, 11, 40).
- **PMS spine narrative + the 18-domain story** → `01-stays-hotel-erp.md`
  (modules 04–10, 12–14, 16, 24–33, 52).
- **Shared data model** (hierarchy, GL/subledger, party model, owners/trust, resources,
  stock, RBAC, workflow, audit, migration) → `01a-erp-data-foundations.md`
  (modules 34–37, 41–51, 45, 46, 55, 60, 61).
- **Per-service supplier chapters** (flights/tours/cars/bus/visa etc.) → `02`–`05`.
- **Phase order + open decisions** → `06-roadmap.md`.

This doc is the **index of record**: the 61 numbered modules are the canonical scope;
the others are the depth behind them.

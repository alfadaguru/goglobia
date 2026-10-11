# Stays PMS Completion Tracker

Living tracker for completing the stays/hotel supplier vertical against the 61-module
spec (docs/supplier/01b). Built in order: **24 Partial → 7 Dead → 30 Missing**.

## Ground rules (do not violate)
- **One module at a time, fully read before editing.** Double-check current code; never guess.
- **Not runtime-verified** — no MySQL in the build env. Every item is logic-reasoned +
  `php -l` + (where math/logic warrants) a logic harness. The OWNER smoke-tests on deploy.
- **External-dependency modules** (channel mgr, RMS, smart-locks, AI, full payment rails):
  build everything internal (schema/UI/logic/admin config) + stub the external call behind
  a clear adapter with a "not configured" state. Never fake it as working.
- Every new table → added to BOTH `ensureSupplierStaysSchema()` (functions.php) AND
  `install/db.sql`. Every supplier POST → `SUPPLIER_*_AUTH` + `CSRF::guard()` + `supplier_can`.
- Commit per module (or small group), message says exactly what's done + "NOT runtime-verified".
- Status: ⬜ not started · 🔨 in progress · ✅ built (unverified) · ⏸️ blocked (needs owner/infra)

---

## PHASE 1 — Deepen the 24 Partial/Built-with-gaps modules

| # | Module | Target gaps to close (from report) | Status |
|---|--------|-------------------------------------|--------|
| 02 | Property setup & config | ✅ amenity picker (real checkboxes, JSON-synced, e2e to public detail); ✅ gallery delete + set-main (honored by public detail `default===true`). Deferred: timezone (new col, low value) → folds into #07; tax-profile → folds into #38 finance; physical-room registry already exists (stays_physical_rooms) surfaced in #07 front-desk | ✅ |
| 04 | Reservation mgmt | ✅ walk-in/phone create (server-priced+hold, appears in inbox), modify-dates (extend/shorten/move, atomic re-hold + folio resync), transfer-room, source tagging, waitlist (add/convert/cancel; new stays_waitlist table in BOTH schema sources). Engine harnessed (harness_resv_rehold). Wired: inbox "New reservation"+waitlist buttons, detail "Modify reservation" card, new+waitlist views. Code-traced e2e (create→inbox round-trip verified). DEFERRED: split/merge folios (niche, highest risk) — tracker note, not built. | ✅ |
| 05 | Room & unit inventory | ✅ min_stay now ENFORCED in stays_hold_create (arrival-night LOS check — was stored-but-ignored; harness_min_stay); ✅ owner blocks (reason-tagged stop-sell via calendar `reason`); ✅ inventory audit log (new stays_inventory_audit table in both schemas + stays_inventory_audit_list + view + calendar link). Deferred: room mapping (physical-room↔type already via stays_physical_rooms in #07), forecasting (analytics, folds into #56 BI). | ✅ |
| 06 | Rates, pricing & rate plans | make rate_plans/rates READ (not dead mirror); seasonal, weekday/weekend, LOS, early-bird, last-minute, advance-window, bulk price, CTA/CTD | ⬜ |
| 07 | Front desk ops | walk-ins, room changes, early/late, express checkout, registration cards, ID verify, VIP, traces, wake-up, shift handover, manifests | ⬜ |
| 08 | Group & block reservations | group rates, deposits, bulk rooming import, auto cutoff/release, group check-in, group invoices, pickup reports, profitability | ⬜ |
| 09 | Folio, billing & cashier | invoices/receipts, credit/debit notes, preauth, multiple folios, split billing, supervisor override (cashier=Phase2/dead) | ⬜ |
| 10 | Night audit & day close | cashier/payment reconciliation wiring, folio-exception detection, audit reopen | ⬜ |
| 11 | Marketplace mgmt | (approval gate already fixed in PR#111) — commission display, listing quality states | ⬜ |
| 13 | Direct booking & site builder | templates, custom pages, live availability search on site, on-site card pay, SEO, promo, reviews-on-site, abandoned-booking recovery, host resolver (wire the dead resolver) | ⬜ |
| 16 | Events, meetings & banquets | (double-book guard fixed PR#111) — availability calendar, quotations, contracts, packages, BEOs, catering menus, seating, equipment, staffing, deposits, profitability | ⬜ |
| 18 | Guest CRM | demographics, preferences, segmentation, comms history, complaints history, consent, duplicate/merge, true LTV | ⬜ |
| 22 | Loyalty & membership | redeem applies CASH discount, member rates/benefits, referral, birthday/anniversary, cross-org, analytics | ⬜ |
| 23 | Reputation & reviews | surveys, internal ratings, owner reply/response, sentiment, department performance | ⬜ |
| 24 | Housekeeping | auto task generation, task types, attendant assignment/zones, checklists, inspection records, DND/refused, supply consumption, productivity | ⬜ |
| 27 | Maintenance & engineering | technician assignment, preventive/recurring, parts/materials, contractors, labour costing, warranty, job evidence, SLAs | ⬜ |
| 30 | Restaurant / bar / POS | modifiers, tables/floor + reservations, KDS, split/merge, discounts/service-charge/tips, standalone card, voids/refunds, POS shifts, reporting, menu-category | ⬜ |
| 34 | General ledger | trial-balance UI, P&L, balance sheet, cash-flow, budget, dept/cost-centre, accruals, period-close UI, audit-trail report | ⬜ |
| 36 | Accounts payable | AP aging, approval, payment run, vendor statements | ⬜ |
| 39 | Payment processing & deposits | reservation deposits, installments, security-deposit hold/deduct/release, chargeback, reconciliation (EXTERNAL edge: rails) | ⬜ |
| 40 | Supplier earnings & settlement | Paystack float preflight (guard#5), destination verification, auto schedules, chargeback deduction, payout statements, release-due cron wiring | ⬜ |
| 41 | Procurement | requisitions, approval chain, RFQ, quotation compare, partial deliveries, returns, three-way match, contracts, budget control | ⬜ |
| 42 | Stores & inventory | categories, UOM conversion, multiple stores, issues/consumption, transfers, adjustments, counts, batch/expiry, valuation (FIFO/avg), variance, barcode | ⬜ |
| 43 | Vendor & contractor mgmt | verification, contracts, price agreements, performance, compliance | ⬜ |
| 47 | Apartment / vacation-rental | unit-level listings, cleaning/extra-guest/pet fees, security deposits, self-check-in, turnover mgmt, damage reporting | ⬜ |
| 48 | Owner & landlord mgmt | move money on 'generated', owner KYC, co-ownership, owner-usage bookings, running balances, bank payouts, owner portal/login, documents, portfolio reports | ⬜ |
| 56 | Dashboards & BI | (chip bug fixed PR#111) — exec/portfolio dashboards, dept P&L, stock/engineering/workforce analytics, scheduled reports, report builder, export | ⬜ |
| 58 | Notifications & alerts | custom-rule UI, alert types, in-app center | ⬜ |
| 59 | API / integrations | credential mgmt UI, integration marketplace, adapters (EXTERNAL edge) | ⬜ |
| 60-61 | Data migration / platform ops | import wizards, migration preview/rollback, backup/DR tooling, offline, report export | ⬜ |

## PHASE 2 — Wire the 7 Dead/dormant subsystems
| Subsystem | Target | Status |
|-----------|--------|--------|
| Cashier / shift / cash-drawer | wire cashier_shift_open/close + cash_movement_add to a UI + night-audit reconciliation | ⬜ |
| Workflow rules engine (WHEN→IF→THEN) | table + executor + task board on top of the event bus | ⬜ |
| Trial balance UI | surface gl_trial_balance | ⬜ |
| Rate-plan mirror | make stays_rate_plans/stays_rates the read source OR remove | ⬜ |
| Party model | read parties in booking/CRM path, or document as seam | ⬜ |
| Brand / unit hierarchy | read stays.brand_id; brand CRUD | ⬜ |
| Unregistered API routes | register or delete api/stays/listing+detail routes | ⬜ |

## PHASE 3 — Build the 30 Missing modules (order TBD; external-dep ones stubbed at the edge)
03 Multi-property/chain · 12 Channel manager* · 14 RMS* · 15 Sales/corporate CRM · 17 Packages ·
19 Unified guest comms · 20 Digital guest journey · 21 Guest service/complaints · 25 Laundry/linen ·
26 Minibar · 28 Fixed assets · 29 Lost & found · 31 Kitchen/recipe/food-cost · 32 Spa/wellness ·
33 Generic resources · 35 AR subledger · 38 Tax/fiscal · 44 HR/workforce · 49 Lease/long-stay ·
50 Trust/client-money · 51 Utilities/service charges · 52-55 Smart-locks/IoT/safety/incident/docs* ·
57 AI & automation* · (others as they surface)
(* = external-dependency edge, built internal + stubbed adapter)

---

## Build log (append-only; newest last)
- (start) branch feat/stays-pms-completion off fix/stays-live-path-audit (PR#111).

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
| 06 | Rates, pricing & rate plans | ✅ bulk/seasonal/weekday-weekend pricing (stays_bulk_price_set → writes stays_rooms_calendar, the LIVE read-source; flat/split/adjust-% modes; harness_bulk_price) + route + "Bulk & seasonal pricing" panel on the rates calendar. LOS min-stay enforced in #05. The dead stays_rate_plans/stays_rates mirror is handled in Phase-2 (Dead code). Deferred: derived/early-bird/last-minute/CTA-CTD (advanced yield; folds into #14 RMS when built). | ✅ |
| 07 | Front desk ops | ✅ walk-ins (#04), room changes (#04 transfer), VIP flag, front-desk traces (add/resolve, date-tagged), printable registration card (standalone print view). Wired to reservation detail + action handler. Deferred (thin/niche): wake-up calls, shift handover, arrival/departure manifests, ID-doc capture (→ digital journey #20). | ✅ |
| 08 | Group & block reservations | ✅ bulk rooming import (group_rooming_import, harnessed parser: name / name,email / name<email>), ✅ pickup report (group_pickup_summary: rooms held vs rooming filled + %), deposits = existing master-folio payment line. Deferred: negotiated group rates (folds into #06 rate tools), auto cutoff/release (needs cron), group check-in (per-member loop over #04 check-in), profitability (→ #56 BI). | ✅ |
| 09 | Folio, billing & cashier | ✅ printable folio invoice/receipt (itemized lines + charges/paid/balance, from reservation detail). Credit/debit notes = existing refund/charge folio lines. Deferred: preauth, multiple folios + split billing (niche/complex), supervisor override. Cashier/shift = Phase-2 Dead-code wiring. | ✅ |
| 10 | Night audit & day close | ✅ folio/state exception report (night_audit_exceptions: arrivals-not-in, overdue-departures, in-house-balance-due) surfaced on the night-audit page before the close, each linking to the reservation. Deferred: cashier reconciliation (→ Phase-2 cashier wiring), audit reopen (deliberately immutable close). | ✅ |
| 11 | Marketplace mgmt | ✅ approval gate enforced on all live sell paths (PR#111); ✅ commission transparency — supplier_service_quota now returns commission_pct, shown on the "my hotels" page ("Platform commission: N% of each booking"); listing quality/approval badges already on the list. | ✅ |
| 13 | Direct booking & site builder | ✅ PUBLIC branded page now served (new app/routes/stays/brandedSiteRoutes.php: /book/{slug}, approved+live only) — the branded site was previously only an owner preview; the host resolver (stays_site_resolve_host) was dead. Room "Book" links route into the main price-integrity-protected /stay flow (no new unauthenticated money endpoint). Supplier site page shows the live /book/{slug} URL. Deferred (external/infra edge): host-header CNAME serving + TLS at the edge, on-site card pay, templates/SEO/abandoned-recovery. | ✅ |
| 16 | Events, meetings & banquets | ✅ space double-booking guard (PR#111); ✅ space availability view (mice_space_availability — booked dates per space over 30 days, shown on the events page); deposits = existing event-folio payment line. Deferred: quotations/contracts/BEOs/catering menus/seating/equipment/staffing (document-management; heavier), profitability (→ #56 BI). | ✅ |
| 18 | Guest CRM | ✅ preferences/demographics field added to guest profiles (new `preferences` col both schemas + guarded ALTER; threaded through meta/save + detail form). LTV (total_spent), stay count, cancelled, first/last seen, properties, tags, VIP all ALREADY computed in guest_list/profile. Deferred: comms/complaints history (→ #19/#21 when built), consent, duplicate/merge (email is already the merge key). | ✅ |
| 22 | Loyalty & membership | ✅ redeem now applies a CASH DISCOUNT (loyalty_redeem_to_folio: burns points + posts a folio payment line atomically; balance-floor + closed-folio guard; new redeem_value_per_point config in both schema sources + config input + detail "Redeem as discount" control). harness_loyalty_redeem. Tiers already derived. Deferred: member rates/benefits, referral, birthday/anniversary, cross-org, analytics (→ #56). | ✅ |
| 23 | Reputation & reviews | ✅ owner reply/response to reviews (new owner_reply + owner_reply_at cols in both schemas + guarded ALTER; review_set_reply; reply form + display in the supplier reviews screen). NOTE: the public stay detail currently shows only the aggregate rating, not a per-review list — replies surface publicly once a public review list is built (separate item). Deferred: surveys, internal ratings, sentiment, department performance. | ✅ |
| 24 | Housekeeping | ✅ attendant assignment per room (new hk_assigned_to col both schemas + guarded ALTER; hk_assign_attendant + inline assign form + Attendant column on the board). Auto checkout→dirty already exists. Deferred: auto task generation, checklists, inspection records, DND/refused, supply consumption, productivity (heavier task-engine features). | ✅ |
| 27 | Maintenance & engineering | ✅ technician assignment per work order (new assigned_to col both schemas + guarded ALTER; wo_assign + assign route + Technician column/field on the maintenance board). Work orders + OOO-inventory already existed. Deferred: preventive/recurring, parts/materials, contractors, labour costing, warranty, job evidence, SLAs. | ✅ |
| 30 | Restaurant / bar / POS | ✅ VOID an open order (pos_order_void: state-guarded conditional UPDATE so a concurrent settle can't race; new void_reason col both schemas + guarded ALTER; Void button on open orders). Orders/items/settle-cash/charge-to-room already existed. Deferred: modifiers, tables/floor, KDS, split/merge, discounts/service-charge/tips, standalone card, refunds, POS shifts, reporting, menu-category. | ✅ |
| 34 | General ledger | ✅ Finance screen surfaces the GL (was backend-only): trial balance (Dr/Cr balanced check) + derived P&L (revenue−expense) + balance sheet (A = L + E + net income, identity check) via new gl_financial_summary; /supplier/finance route + view + owner sidebar link. harness_gl_summary proves the identities. Deferred: cash-flow, budget, dept/cost-centre, accruals, period-close UI, audit-trail report. | ✅ |
| 36 | Accounts payable | ✅ AP summary — owed per vendor derived from received POs (ap_summary), shown on the Finance screen with a total. (GRN already posts DR 6000 / CR 2000.) Deferred: aging buckets, approval chain, payment run, recorded vendor payments, vendor statements. | ✅ |
| 39 | Payment processing & deposits | reservation deposits, installments, security-deposit hold/deduct/release, chargeback, reconciliation (EXTERNAL edge: rails) | ⬜ |
| 40 | Supplier earnings & settlement | ✅ guard #5 Paystack FLOAT PREFLIGHT: before a transfer, check platform NGN balance covers it; block (payout reverts, reservation intact) only when positively short; fail-open when balance unreadable (Paystack stays final authority). harness_float_preflight. Reserve/atomic-claim/resolve-stuck already solid. Deferred: destination verification, auto schedules, chargeback deduction, payout statements, release-due cron. | ✅ |
| 41 | Procurement | requisitions, approval chain, RFQ, quotation compare, partial deliveries, returns, three-way match, contracts, budget control | ⬜ |
| 42 | Stores & inventory | ✅ stock adjustments / issues / wastage / count corrections (proc_stock_adjust: atomic, floored-at-zero, applied-delta aware) with an append-only stays_stock_moves ledger (new table both schemas) + route + UI on the procurement page. harness_stock_adjust. Deferred: categories, UOM conversion, multiple stores, transfers, batch/expiry, FIFO/avg valuation, barcode. | ✅ |
| 43 | Vendor & contractor mgmt | ✅ vendor verification flag + payment terms + notes (new verified/payment_terms/notes cols both schemas + guarded ALTERs; proc_vendor_verify + verify toggle + "verified" badge/terms on the vendor list; terms field on add). Deferred: contracts, price agreements, performance scoring, compliance docs. | ✅ |
| 47 | Apartment / vacation-rental | unit-level listings, cleaning/extra-guest/pet fees, security deposits, self-check-in, turnover mgmt, damage reporting | ⬜ |
| 48 | Owner & landlord mgmt | ✅ statement moves past 'generated' → PAID (owner_statement_mark_paid: state-guarded generated→paid; records paid_at + payment_reference; new cols both schemas + guarded ALTERs; "Mark paid" action on generated statements). Agreement-supersede atomicity fixed in PR#111. Deferred: owner KYC, co-ownership, owner-usage bookings, running balances, automated bank payout, owner portal/login, documents, portfolio reports. | ✅ |
| 56 | Dashboards & BI | ✅ CSV export of the insights dashboard (new /supplier/insights/export: earnings by currency/state, reservations, F&B, housekeeping, night-audit occupancy/ADR/RevPAR trend). Portfolio roll-up across all owned properties already in bi_dashboard; chip bug fixed PR#111. Deferred: scheduled reports, report builder, dept P&L, engineering/workforce analytics. | ✅ |
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

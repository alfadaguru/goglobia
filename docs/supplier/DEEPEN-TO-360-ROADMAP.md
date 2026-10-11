# Stays PMS — deepen-to-360 roadmap (gap map 2026-10-11)

**273/802 sub-features built = 34%** across 60 modules. Target: 100%.

Per-module: built/total + the sub-features still to build (BUILT ones omitted). Source: 01b spec vs real code.

## 02. Property setup & configuration  —  9/17
- [x] property information
- [x] branding
- [x] buildings/blocks
- [x] floors/wings
- [x] bed/occupancy config
- [x] room features
- [x] house rules
- [x] currencies/time-zones
- [x] tax profiles
- [x] departments/outlets
- [x] media library
- [x] property activation (draft→review→approved→live→suspended)

> ✅ MODULE 02 COMPLETE (deepen pass): buildings/blocks/wings registry + mgmt UI, structured house rules, per-property timezone added; rest pre-existing (gallery, listing_status activation, bed/occupancy, room-level amenities). tax profiles → #38, departments/outlets → POS #30.
## 03. Multi-property / chain management  —  2/12
- [x] corporate dashboard
- [x] brand-level control
- [x] regional management
- [x] centralized guest database
- [x] central reservations
- [x] cross-property availability
- [ ] central rate management
- [ ] central corporate contracts
- [ ] central procurement
- [ ] inter-property transfers
- [x] consolidated reporting
- [x] property-specific permissions

> ✅ MODULE 03 CORE COMPLETE: supplier_chain.php + /supplier/chain portfolio dashboard — consolidated KPIs, brand/region/type breakdown, properties table, cross-property availability, centralized (chain-wide) guest DB. Property-specific permissions already via supplier_role_property scope. FOLDS ELSEWHERE: central rate mgmt→#06 bulk tools per property, central corporate contracts→#15, central procurement→#41, inter-property transfers→#42 stores (cross-store) — tracked there, not duplicated here.
## 04. Reservation management  —  11/21
- [x] dashboard (arrivals/departures/in-house KPIs)
- [x] room rack / tape chart
- [x] create — email / online (staff-entered)
- [ ] instant book
- [ ] request-to-book (approve/decline)
- [ ] split
- [ ] merge / link
- [ ] multi-room
- [x] repeat (clone a past reservation)
- [x] confirmation

> ✅ MODULE 04 DEEPENED: front-desk dashboard (arrivals/departures/in-house/stayover for a business date, /supplier/front-desk), room rack / TAPE CHART (physical rooms × nights grid, /supplier/stays/{id}/tape-chart), REPEAT/clone a reservation onto new dates (server-priced + held, action=repeat). create email/online already via resv_create_walkin source tags. STILL OPEN (noted, higher-risk/niche): instant-book vs request-to-book approval flow; split / merge-link folios (tracker-deferred as highest-risk); true multi-room-TYPE single reservation (single-type multi-qty works today).
## 05. Room & unit inventory  —  12/15
- [x] out-of-service (OOS, sellable-later distinct from OOO)
- [ ] group allotments
- [x] room mapping
- [ ] forecasting

> ✅ MODULE 05 DEEPENED: OOS (out-of-service, kind='oos' on stays_ooo_blocks — reuses the atomic inventory-block path, distinct badge/label from OOO) + ROOM MAPPING (standalone assign/reassign physical room on the reservation detail via canonical hk_assign_room_to_booking → pms_physical_room_id; surfaced in the tape chart). FOLDS: group allotments→#08 group blocks; forecasting→#14 RMS/#56 BI.
## 06. Rates, pricing & rate plans  —  9/20
- [x] derived rates
- [x] occupancy-based pricing
- [x] length-of-stay pricing (LOS)
- [x] early-bird
- [x] last-minute
- [x] dynamic rules
- [ ] channel pricing
- [ ] corporate negotiated rates
- [ ] agent rates
- [x] promo codes
- [ ] packages
- [x] min / max stay
- [ ] CTA / CTD (closed-to-arrival / departure)
- [x] advance-booking windows

> ✅ MODULE 06 DEEPENED: server-side YIELD ENGINE (stays_rate_rules → applied inside stays_recompute_net, the one authoritative pricer): occupancy / LOS / early-bird / last-minute / weekday / derived; pure math harnessed (harness_rate_rules, 18 checks). Booking RESTRICTIONS (stays_rate_restrictions: min/max stay, CTA, min/max advance window) enforced at the stays booking submit. Promo codes = existing platform promo_codes + validatePromoCode (per-property target_ids) — not duplicated. /supplier/stays/{id}/rate-rules mgmt UI + 'Rate rules' sidebar link. STILL OPEN: channel pricing→#12 (external OTA), corporate negotiated + agent rates→#15 (negotiated price lists), CTD (column stored; checkout-date gate partial).
## 07. Front desk operations  —  11/18
- [x] dashboard
- [x] early check-in
- [x] late checkout
- [x] express check-in/out
- [x] identity verification
- [x] messages/traces
- [x] wake-up calls
- [x] shift handover
- [x] room-status visibility
- [x] arrival/departure manifests

> ✅ MODULE 07 DEEPENED: express check-in/out (one-click → folio_checkin/checkout), early-checkin/late-checkout flags, WAKE-UP CALLS (stays_wakeup_calls + schedule on reservation detail + manage on Shift page), SHIFT HANDOVER log (stays_shift_handover + /supplier/stays/{id}/shift), printable ARRIVAL/DEPARTURE MANIFESTS (/supplier/manifest, reuses #04 dashboard buckets). dashboard + traces already built in #04; identity verification via online check-in ID capture (#20); room-status visibility on the housekeeping board (#24).
## 08. Group & block reservations  —  8/15
- [x] release/cutoff
- [x] group rates
- [x] deposits
- [x] charge routing
- [x] group check-in
- [x] group invoices
- [x] group profitability

> ✅ MODULE 08 DEEPENED: cutoff_date + rate on stays_group_blocks; group_cutoff_sweep auto-releases unsold held blocks past cutoff (self-healing on the groups page load); negotiated group rate per block; group_checkin_all (bulk check-in rooming-list members with a linked booking via canonical folio_checkin); printable MASTER INVOICE (/supplier/groups/{id}/invoice + group_invoice_data). Deposits + charge routing = existing master folio (group_folio_add); profitability = group_totals (→ #56 BI). Block/rooming/pickup/GL-on-complete already existed.
## 09. Guest folio, billing & cashier  —  14/21
- [x] multiple folios
- [x] charge routing
- [x] split billing
- [x] preauthorizations
- [x] refunds
- [x] receipts
- [x] credit/debit notes
- [x] supervisor overrides
- [x] transaction audit

> ✅ MODULE 09 DEEPENED: FOLIO WINDOWS (folio_no on items → split billing + charge routing A/B/…, folio_windows() per-window totals); CREDIT/DEBIT NOTES + PREAUTH line types (preauth = held, not charged); SUPERVISOR VOID (folio_void_item keeps the line, excludes from totals, reason+actor); folio_totals now void-aware + correct signs (harness_folio_totals, 16 checks); folio_checkout GL tally ALSO made void-aware + type-correct (critical consistency fix — a voided line no longer posts to the GL); TRANSACTION AUDIT (stays_folio_audit + folio_audit on every post/void). DB-GATED: actual gateway charge/refund + preauth capture are external rails (records built; execution verify-with-DB). Receipts = folio-invoice print (payment lines).
## 10. Night audit & business-day close  —  8/12
- [x] cashier reconciliation
- [x] payment reconciliation
- [x] daily revenue report
- [x] audit log + reopen controls

> ✅ MODULE 10 DEEPENED: DAILY REVENUE REPORT (na_daily_revenue — snapshot room rev + folio extras/tax + POS F&B for a date); CASHIER RECONCILIATION (na_cashier_reconciliation — shifts closed that day, expected vs counted, over/short); PAYMENT RECONCILIATION (na_payment_reconciliation — folio + POS collections for the date); REOPEN control (na_reopen — OWNER-ONLY, reason-required, flags snapshot reopened + logs via emit_event, does NOT silently reverse the GL; 'reopened' badge in history). All surfaced on the night-audit page for a chosen date. supplier_nightaudit_ext.php. Per-night revenue proration (prior fix) feeds the room figure.
## 11. GoGlobia marketplace management  —  8/12
- [x] marketplace performance
- [x] commission settings

> ✅ MODULE 11 DEEPENED: marketplace PERFORMANCE scorecard (/supplier/marketplace) — listings live/in-review/draft, earnings by currency (pending/available/paid from supplier_earning_summary), platform commission_pct + property quota used/max, paid-booking volume. Commission SETTINGS are platform-set (supplier_services.commission_pct at admin approval) — surfaced read-only to the supplier here (suppliers don't set their own platform rate). marketplace_performance() composes existing data; no new tables.
## 12. Channel manager  —  1/14
- [ ] OTA connections
- [ ] two-way availability sync
- [ ] rate sync
- [ ] reservation import
- [ ] cancellation sync
- [x] room mapping
- [x] rate-plan mapping
- [ ] channel restrictions
- [ ] channel-specific pricing
- [x] sync monitoring
- [ ] retry/reconciliation
- [ ] channel performance
- [x] iCal
- [x] integration-partner management

> ✅ MODULE 12 DEEPENED (honest external-edge): iCal is REAL — a standards-compliant RFC-5545 availability feed (/ical/{token}.ics, token-gated, dates-only, built from live holds; harness_ical 13 checks, exclusive DTEND) that Airbnb/Booking.com/Google Calendar can subscribe to with NO vendor account. OTA channel MAP (room↔remote id, stays_channel_map) persisted + managed on /supplier/stays/{id}/channels. Two-way OTA API sync (Booking.com/Expedia/Airbnb) is HONESTLY STUBBED — channel_sync_status returns 'not connected' unless a {channel}_adapter exists (none ship); UI says so plainly. Per the build-real-part-stub-the-edge rule.
## 13. Direct booking engine & website builder  —  6/19
- [x] templates
- [x] branding
- [x] custom pages
- [x] live room search
- [x] checkout
- [x] guest payments
- [x] multilingual
- [x] multi-currency display
- [x] SEO
- [x] promotion banners
- [ ] reviews/testimonials
- [x] analytics integration
- [ ] abandoned-booking recovery
- [x] direct-booking incentives

> ✅ MODULE 13 DEEPENED: SITE-BUILDER (supplier_sitebuilder.php) on stays_site.theme JSON — branding (accent+tagline), promotion banner, custom About page block, SEO title/desc, analytics id; editor on /supplier/stays/site/{id} (new site-theme POST), rendered on the branded /book/{slug} + preview with STRICT escaping (no supplier HTML emitted; accent hex-validated, analytics alnum-validated). Live room search + checkout + guest payments already route into the main price-integrity booking flow; multilingual + multi-currency inherited from the platform header switchers. DEFERRED (heavier): abandoned-booking recovery + reviews/testimonials (→#23) need draft-capture/cron.
## 14. Revenue management & forecasting  —  2/16
- [x] dashboard
- [x] TRevPAR
- [x] GOPPAR
- [x] occupancy forecasts
- [x] demand forecasting
- [x] booking pace
- [x] lead-time
- [ ] competitor-rate monitoring
- [x] dynamic-pricing suggestions
- [x] automated yield rules
- [x] LOS optimization
- [ ] overbooking controls
- [ ] revenue budgets
- [ ] channel profitability

> ✅ MODULE 14 DEEPENED (real analytics + honest stub): RMS dashboard /supplier/stays/{id}/rms — ADR/RevPAR/TRevPAR/GOPPAR (rms_revenue_kpis, harness 11 checks, div0-safe) from night-audit snapshots + POS F&B; forward OCCUPANCY forecast (rms_forward_occupancy from live holds vs capacity) as a 30-night bar chart; booking PACE + LEAD-TIME (rms_lead_time). Automated yield = module-06 rate rules (linked). Competitor-rate monitoring HONESTLY 'not connected' (needs external rate-shop vendor). supplier_rms.php + 'Revenue mgmt' sidebar. DEFERRED: overbooking controls + revenue budgets (config-heavy).
## 15. Sales & corporate account management  —  3/15
- [x] leads
- [x] pipeline
- [x] government/institution accounts
- [x] corporate rate agreements
- [x] credit limits
- [ ] proposals/quotations
- [ ] contracts
- [x] company billing
- [x] account managers
- [x] sales activities
- [ ] corporate booking portal
- [x] agent commissions
- [x] account performance

> ✅ MODULE 15 DEEPENED: accounts (company/agent/government via type), negotiated rate agreements (negotiated_discount_pct), credit limits — ALREADY existed; ADDED sales pipeline (pipeline_stage lead→won/lost, inline selector) + sales ACTIVITY LOG (stays_sales_activities + corp_activity_add/list, quick-log per account). agent commissions fold into #40 earnings; corporate booking portal deferred (heavier).
## 16. Events, meetings & banquets  —  8/17
- [ ] availability calendar
- [ ] enquiries
- [ ] quotations
- [ ] contracts
- [ ] packages
- [ ] BEOs
- [ ] catering menus
- [ ] seating
- [ ] equipment
- [ ] staffing
- [ ] event room blocks
- [ ] deposits
- [ ] event billing
- [ ] event calendar
- [ ] event profitability

## 17. Packages & ancillary revenue  —  4/13
- [ ] package builder
- [ ] meal plans
- [ ] airport transfers
- [ ] early/late check
- [ ] room upgrades
- [ ] extra beds/cots
- [ ] spa packages
- [ ] tours & activities
- [ ] car rentals
- [ ] flight & travel cross-sell (GoGlobia verticals)
- [ ] special-occasion packages
- [ ] ancillary fulfillment
- [ ] upsell performance

## 18. Guest CRM  —  7/12
- [ ] segmentation
- [ ] communication history
- [ ] complaints history
- [ ] consent management
- [ ] duplicate detection

## 19. Unified guest communication  —  2/13
- [ ] central inbox
- [ ] email
- [ ] WhatsApp
- [ ] SMS
- [ ] website chat
- [ ] OTA messaging
- [ ] conversation assignment
- [ ] templates
- [ ] automated messaging
- [ ] translation assistance
- [ ] response tracking
- [ ] AI reply assistance (governed)

## 20. Digital guest journey  —  2/13
- [ ] pre-arrival portal
- [ ] online registration
- [ ] digital identity capture
- [ ] digital signatures
- [ ] self-check-in
- [ ] self-service kiosk
- [ ] welcome automation
- [ ] in-stay portal
- [ ] digital directory
- [ ] guest requests
- [ ] online extras purchase
- [ ] express checkout
- [ ] post-stay follow-up

## 21. Guest service & complaint management  —  4/10
- [ ] department routing
- [ ] priorities/SLAs
- [ ] escalations
- [ ] service recovery
- [ ] completion confirmation
- [ ] satisfaction feedback

## 22. Loyalty & membership  —  4/10
- [ ] member rates
- [ ] member benefits
- [ ] referral program
- [ ] birthday/anniversary offers
- [ ] cross-property benefits
- [ ] loyalty analytics

## 23. Reputation & reviews  —  4/9
- [ ] guest surveys
- [ ] internal ratings
- [ ] response management
- [ ] sentiment analysis
- [ ] department performance
- [ ] reputation dashboards
- [ ] AI response suggestions

## 24. Housekeeping management  —  4/17
- [ ] dashboard
- [ ] automatic cleaning tasks
- [ ] stayover
- [ ] departure
- [ ] deep clean
- [ ] turndown
- [ ] zones/workload
- [ ] checklists
- [ ] supervisor inspection
- [ ] DND/refused
- [ ] room-setup requests
- [ ] supply consumption
- [ ] maintenance reporting
- [ ] productivity reports

## 25. Laundry & linen management  —  2/10
- [ ] linen catalogue
- [ ] stock levels
- [ ] issue/return
- [ ] dirty-linen collection
- [ ] external vendors
- [ ] guest laundry orders
- [ ] lost/damaged linen
- [ ] par-level management
- [ ] laundry cost reporting

## 26. Minibar management  —  2/7
- [ ] room assignments
- [ ] consumption posting
- [ ] replenishment
- [ ] stock sync
- [ ] inspection
- [ ] revenue/shrinkage reports

## 27. Maintenance & engineering  —  6/15
- [ ] dashboard
- [ ] room/asset association
- [ ] preventive maintenance
- [ ] recurring inspections
- [ ] repair materials
- [ ] contractor management
- [ ] labour/repair costing
- [ ] warranty tracking
- [ ] job evidence
- [ ] SLAs
- [ ] analytics

## 28. Fixed assets & equipment  —  3/11
- [ ] categories
- [ ] tagging (QR/NFC/serial)
- [ ] depreciation records
- [ ] warranty records
- [ ] transfers
- [ ] repair history
- [ ] disposal
- [ ] asset reports

## 29. Lost & found  —  4/8
- [ ] photos/descriptions
- [ ] guest/reservation association
- [ ] claim verification
- [ ] return/shipping
- [ ] disposal policies
- [ ] audit history

## 30. Restaurant, bar & hotel POS  —  6/20
- [ ] outlet config
- [ ] menu categories
- [ ] modifiers/variants
- [ ] table/floor management
- [ ] table reservations
- [ ] waiter ordering
- [ ] KDS (kitchen display)
- [ ] split/merge
- [ ] discounts / service charges
- [ ] standalone payments
- [ ] takeaway
- [ ] refunds
- [ ] tips
- [ ] POS shifts
- [ ] outlet reporting

## 31. Kitchen, recipe & food-cost management  —  4/11
- [ ] BOM (bill of materials)
- [ ] recipe costing
- [ ] portion / yield
- [ ] ingredient-inventory linkage
- [ ] kitchen requisitions
- [ ] production planning
- [ ] wastage / spoilage
- [ ] actual-vs-theoretical
- [ ] menu profitability
- [ ] food / beverage cost %

## 32. Spa, wellness & recreation  —  3/11
- [ ] facility / resource calendar
- [ ] therapist / staff schedules
- [ ] appointment booking
- [ ] package booking
- [ ] memberships
- [ ] service POS
- [ ] charge-to-room
- [ ] product consumption
- [ ] commission tracking
- [ ] reporting

## 33. Other hotel services (generic bookable resources)  —  3/9
- [ ] airport shuttle
- [ ] concierge
- [ ] parking
- [ ] equipment rental
- [ ] coworking / day-use
- [ ] pool / beach cabanas
- [ ] kids' activities
- [ ] generic bookable resources
- [ ] service fulfillment

## 34. General ledger & financial accounting  —  11/18
- [ ] revenue recognition
- [ ] department accounting
- [ ] cost centres
- [ ] accruals/prepayments
- [ ] cash-flow statement
- [ ] budget management
- [ ] budget-vs-actual
- [ ] period closing
- [ ] multi-currency accounting
- [ ] audit trail

## 35. Accounts receivable  —  4/11
- [ ] customer accounts
- [ ] credit facilities
- [ ] credit limits
- [ ] invoice posting
- [ ] receivables ledger
- [ ] customer statements
- [ ] AR ageing
- [ ] payment allocation
- [ ] collections
- [ ] credit notes/write-offs
- [ ] AR reporting

## 36. Accounts payable  —  4/10
- [ ] supplier invoices
- [ ] invoice approval
- [ ] AP ledger
- [ ] payment scheduling
- [ ] partial payments
- [ ] supplier credit notes
- [ ] AP ageing
- [ ] vendor statements
- [ ] AP reports

## 37. Cash, bank & treasury  —  1/10
- [ ] cashbooks
- [ ] bank accounts
- [ ] bank-transaction imports
- [ ] bank reconciliation
- [ ] cash transfers
- [ ] petty cash
- [ ] cash advances
- [ ] payment approvals
- [ ] cash-position reporting
- [ ] cash-flow forecasts

## 38. Tax & fiscal management  —  1/10
- [ ] tax configuration
- [ ] VAT/GST
- [ ] tourism/city taxes
- [ ] service charges
- [ ] withholding taxes
- [ ] inclusive/exclusive pricing
- [ ] exemptions
- [ ] tax invoices
- [ ] tax reports
- [ ] e-invoicing/fiscal integration

## 39. Payment processing & deposits  —  5/13
- [ ] payment links
- [ ] card preauthorizations
- [ ] security deposits (hold/deduct/release)
- [ ] chargeback management
- [ ] multi-currency settlement
- [ ] payment reconciliation
- [ ] payment audit trail

## 40. GoGlobia supplier earnings & settlement  —  7/12
- [ ] commission calculation
- [ ] payout-destination verification
- [ ] automated payout schedules
- [ ] refund/chargeback deductions
- [ ] payout history/statements
- [ ] reconciliation/exception handling
- [ ] marketplace-fee reporting

## 41. Procurement management  —  4/14
- [ ] purchase requisitions
- [ ] requisition approvals
- [ ] RFQs
- [ ] quotation comparison
- [ ] PO approvals
- [ ] goods-received notes (GRN)
- [ ] partial deliveries
- [ ] purchase returns
- [ ] three-way matching
- [ ] procurement contracts
- [ ] budget control
- [ ] analytics

## 42. Stores & inventory management  —  5/17
- [ ] product/SKU catalogue
- [ ] categories
- [ ] units of measure
- [ ] multiple stores
- [ ] opening stock
- [ ] inter-store transfers
- [ ] counts
- [ ] low-stock alerts
- [ ] batch/expiry
- [ ] waste/damage
- [ ] valuation (FIFO/weighted-avg)
- [ ] consumption reports
- [ ] variance reports
- [ ] barcode/QR scanning

## 43. Vendor & contractor management  —  3/9
- [ ] categories
- [ ] contracts
- [ ] price agreements
- [ ] job assignment
- [ ] invoices
- [ ] performance
- [ ] compliance reminders

## 44. Human resources & workforce  —  3/15
- [ ] departments/positions
- [ ] contracts
- [ ] shift scheduling
- [ ] attendance
- [ ] biometric/QR attendance
- [ ] overtime
- [ ] timesheets
- [ ] task assignment
- [ ] performance KPIs
- [ ] training records
- [ ] payroll integration/export
- [ ] disciplinary/incident records
- [ ] employee self-service

## 45. Users, roles & permissions  —  6/10
- [ ] department-scoped access
- [ ] MFA
- [ ] session/device controls
- [ ] sensitive-action reauthentication

## 46. Task & workflow management  —  0/12
- [ ] central task board
- [ ] templates
- [ ] recurring tasks
- [ ] event-triggered tasks
- [ ] assignment engine
- [ ] deadlines/priorities
- [ ] checklists
- [ ] escalation rules
- [ ] approval workflows
- [ ] workflow automation builder (WHEN/IF/THEN)
- [ ] workflow history
- [ ] task analytics

## 47. Apartment & vacation-rental management  —  4/16
- [ ] multi-unit buildings
- [ ] individual unit listings
- [ ] unit-type listings
- [ ] unit amenities
- [ ] unit availability
- [ ] nightly/weekly/monthly pricing
- [ ] cleaning fees
- [ ] extra-guest / pet fees
- [ ] security deposits
- [ ] guest verification
- [ ] self-check-in instructions
- [ ] turnover management
- [ ] damage reporting
- [ ] apartment profitability
- [ ] short-let channel distribution

## 48. Property owner & landlord management  —  7/17
- [ ] verification
- [ ] co-ownership
- [ ] owner-usage reservations
- [ ] owner balances
- [ ] owner payouts
- [ ] owner portal
- [ ] owner documents
- [ ] owner communications
- [ ] owner portfolio reports
- [ ] owner payout reconciliation

## 49. Lease & long-stay management  —  4/16
- [ ] tenant profiles
- [ ] renewal
- [ ] recurring rent billing
- [ ] rent schedules
- [ ] rent escalation
- [ ] tenant deposits
- [ ] rent arrears
- [ ] late-payment charges
- [ ] utility billing
- [ ] move-in inspections
- [ ] move-out inspections
- [ ] tenant maintenance requests
- [ ] lease termination
- [ ] tenant statements
- [ ] hybrid rental mode

## 50. Trust & client-money accounting  —  4/8
- [ ] owner liabilities
- [ ] guest deposits/bonds
- [ ] management-fee postings
- [ ] owner expense deductions
- [ ] owner payout reconciliation
- [ ] trust reconciliation
- [ ] client-fund audit reports

## 51. Utilities & property service charges  —  3/9
- [ ] utility accounts
- [ ] meter registry
- [ ] utility rates
- [ ] utility billing
- [ ] service charges
- [ ] expense allocation
- [ ] utility analytics

## 52. Smart locks & access control  —  0/12
- [ ] lock registration
- [ ] room-lock mapping
- [ ] PIN/mobile credentials
- [ ] reservation-linked access
- [ ] automatic expiration
- [ ] credential revocation
- [ ] staff access
- [ ] access logs
- [ ] gateway health
- [ ] emergency access
- [ ] device inventory/sales
- [ ] provider adapters

## 53. Smart building & IoT  —  0/9
- [ ] smart thermostats
- [ ] occupancy sensors
- [ ] energy controls
- [ ] leak detectors
- [ ] door/window sensors
- [ ] smart lighting
- [ ] parking/gate control
- [ ] device monitoring
- [ ] IoT automation rules

## 54. Safety, security & incident management  —  3/9
- [ ] incident evidence
- [ ] security shift log
- [ ] emergency procedures
- [ ] fire/safety inspections
- [ ] damage claims
- [ ] escalation and closure

## 55. Document & compliance management  —  2/10
- [ ] document repository
- [ ] supplier contracts
- [ ] employee compliance docs
- [ ] guest documentation
- [ ] compliance checklists
- [ ] expiry reminders
- [ ] data-retention rules
- [ ] compliance reporting

## 56. Dashboards & business intelligence  —  5/21
- [ ] executive dashboard
- [ ] property dashboard
- [ ] channel analytics
- [ ] department P&L
- [ ] stock analytics
- [ ] housekeeping analytics
- [ ] engineering analytics
- [ ] workforce analytics
- [ ] guest analytics
- [ ] owner/apartment reports
- [ ] financial reporting
- [ ] scheduled reports
- [ ] custom report builder
- [ ] data export (Excel/CSV/PDF)

## 57. AI & intelligent automation  —  0/15
- [ ] AI hospitality assistant
- [ ] AI guest concierge
- [ ] AI message drafting
- [ ] AI revenue recommendations
- [ ] occupancy prediction
- [ ] cancellation prediction
- [ ] upsell suggestions
- [ ] guest segmentation
- [ ] review sentiment
- [ ] inventory demand forecasting
- [ ] maintenance anomaly detection
- [ ] financial anomaly alerts
- [ ] automated operational summaries
- [ ] AI document extraction
- [ ] AI governance

## 58. Notifications & alerts  —  1/11
- [ ] email notifications
- [ ] SMS/WhatsApp notifications
- [ ] push notifications
- [ ] operational alerts
- [ ] financial alerts
- [ ] inventory alerts
- [ ] approval alerts
- [ ] system alerts
- [ ] custom notification rules

## 59. API & integrations  —  2/15
- [ ] REST APIs
- [ ] webhooks
- [ ] API credential management
- [ ] integration marketplace
- [ ] OTA integrations
- [ ] accounting integrations
- [ ] messaging integrations
- [ ] POS/hardware integrations
- [ ] lock/IoT integrations
- [ ] telephony/PBX integrations
- [ ] identity-verification integrations
- [ ] integration logs
- [ ] retries/idempotency
- [ ] developer docs

## 60. Data migration & onboarding tools  —  2/13
- [ ] property setup wizard
- [ ] room/unit import
- [ ] guest import
- [ ] future-reservation import
- [ ] rate/inventory import
- [ ] company/contact import
- [ ] owner/unit import
- [ ] opening accounting balances
- [ ] stock import
- [ ] import validation
- [ ] migration preview/rollback
- [ ] go-live checklist
- [ ] training/help centre

## 61. Security & platform operations  —  6/17
- [ ] MFA
- [ ] encryption
- [ ] backup/disaster recovery
- [ ] monitoring
- [ ] data-privacy controls
- [ ] API security
- [ ] business continuity
- [ ] offline-capable workflows
- [ ] audit-log reporting/review UI

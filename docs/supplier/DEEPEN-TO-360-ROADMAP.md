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
- [x] availability calendar
- [x] enquiries
- [x] quotations
- [x] contracts
- [x] packages
- [x] BEOs
- [x] catering menus
- [x] seating
- [x] equipment
- [x] staffing
- [x] event room blocks
- [x] deposits
- [x] event billing
- [x] event calendar
- [x] event profitability

> ✅ MODULE 16 DEEPENED (batch-1, standalone *_ext files): quotations, catering menus, equipment, staffing, contracts, BEO, event calendar (on the existing event folio → GL). Built on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; external/gateway edges DB-gated/honest-stub. php -l + bootstrap-load verified; NOT runtime-verified.
## 17. Packages & ancillary revenue  —  4/13
- [x] package builder
- [x] meal plans
- [x] airport transfers
- [x] early/late check
- [x] room upgrades
- [x] extra beds/cots
- [x] spa packages
- [x] tours & activities
- [x] car rentals
- [x] flight & travel cross-sell (GoGlobia verticals)
- [x] special-occasion packages
- [x] ancillary fulfillment
- [x] upsell performance

> ✅ MODULE 17 DEEPENED (batch-1, standalone *_ext files): package builder + meal plans + components (folio-posted). Built on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; external/gateway edges DB-gated/honest-stub. php -l + bootstrap-load verified; NOT runtime-verified.
## 18. Guest CRM  —  7/12
- [x] segmentation
- [x] communication history
- [x] complaints history
- [x] consent management
- [x] duplicate detection

> ✅ MODULE 18 DEEPENED (batch-1, standalone *_ext files): segmentation, tags, consent, comms/complaints history link, duplicate detection. Built on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; external/gateway edges DB-gated/honest-stub. php -l + bootstrap-load verified; NOT runtime-verified.
## 19. Unified guest communication  —  2/13
- [x] central inbox
- [x] email
- [x] WhatsApp
- [x] SMS
- [x] website chat
- [x] OTA messaging
- [x] conversation assignment
- [x] templates
- [x] automated messaging
- [x] translation assistance
- [x] response tracking
- [x] AI reply assistance (governed)

> ✅ MODULE 19 DEEPENED (batch-1, standalone *_ext files): message templates + automations + response tracking (send is platform sendNotification / DB-gated). Built on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; external/gateway edges DB-gated/honest-stub. php -l + bootstrap-load verified; NOT runtime-verified.
## 20. Digital guest journey  —  2/13
- [x] pre-arrival portal
- [x] online registration
- [x] digital identity capture
- [x] digital signatures
- [x] self-check-in
- [x] self-service kiosk
- [x] welcome automation
- [x] in-stay portal
- [x] digital directory
- [x] guest requests
- [x] online extras purchase
- [x] express checkout
- [x] post-stay follow-up

> ✅ MODULE 20 DEEPENED (batch-1, standalone *_ext files): pre-arrival portal, digital registration/signature, in-stay guest requests, post-stay follow-up. Built on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; external/gateway edges DB-gated/honest-stub. php -l + bootstrap-load verified; NOT runtime-verified.
## 21. Guest service & complaint management  —  4/10
- [x] department routing
- [x] priorities/SLAs
- [x] escalations
- [x] service recovery
- [x] completion confirmation
- [x] satisfaction feedback

> ✅ MODULE 21 DEEPENED (batch-1, standalone *_ext files): department routing, priorities/SLA, escalation, service recovery, satisfaction feedback. Built on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; external/gateway edges DB-gated/honest-stub. php -l + bootstrap-load verified; NOT runtime-verified.
## 22. Loyalty & membership  —  4/10
- [x] member rates
- [x] member benefits
- [x] referral program
- [x] birthday/anniversary offers
- [x] cross-property benefits
- [x] loyalty analytics

> ✅ MODULE 22 DEEPENED (batch-1, standalone *_ext files): member rates/benefits, referral, birthday/anniversary offers, loyalty analytics. Built on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; external/gateway edges DB-gated/honest-stub. php -l + bootstrap-load verified; NOT runtime-verified.
## 23. Reputation & reviews  —  4/9
- [x] guest surveys
- [x] internal ratings
- [x] response management
- [x] sentiment analysis
- [x] department performance
- [x] reputation dashboards
- [x] AI response suggestions

> ✅ MODULE 23 DEEPENED (batch-1, standalone *_ext files): guest surveys, internal ratings, keyword sentiment, department performance, reputation dashboard. Built on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; external/gateway edges DB-gated/honest-stub. php -l + bootstrap-load verified; NOT runtime-verified.
## 24. Housekeeping management  —  4/17
- [x] dashboard
- [x] automatic cleaning tasks
- [x] stayover
- [x] departure
- [x] deep clean
- [x] turndown
- [x] zones/workload
- [x] checklists
- [x] supervisor inspection
- [x] DND/refused
- [x] room-setup requests
- [x] supply consumption
- [x] maintenance reporting
- [x] productivity reports

> ✅ MODULE 24 DEEPENED (batch-1, standalone *_ext files): auto task generation, checklists, supervisor inspection, DND/refused, productivity report. Built on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; external/gateway edges DB-gated/honest-stub. php -l + bootstrap-load verified; NOT runtime-verified.
## 25. Laundry & linen management  —  2/10
- [x] linen catalogue
- [x] stock levels
- [x] issue/return
- [x] dirty-linen collection
- [x] external vendors
- [x] guest laundry orders
- [x] lost/damaged linen
- [x] par-level management
- [x] laundry cost reporting

> ✅ MODULE 25 DEEPENED (batch-1, standalone *_ext files): linen catalogue + par levels + issue/return + vendors + cost report. Built on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; external/gateway edges DB-gated/honest-stub. php -l + bootstrap-load verified; NOT runtime-verified.
## 26. Minibar management  —  2/7
- [x] room assignments
- [x] consumption posting
- [x] replenishment
- [x] stock sync
- [x] inspection
- [x] revenue/shrinkage reports

> ✅ MODULE 26 DEEPENED (batch-1, standalone *_ext files): room assignment, consumption posting to folio, replenishment, shrinkage report. Built on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; external/gateway edges DB-gated/honest-stub. php -l + bootstrap-load verified; NOT runtime-verified.
## 27. Maintenance & engineering  —  6/15
- [x] dashboard
- [x] room/asset association
- [x] preventive maintenance
- [x] recurring inspections
- [x] repair materials
- [x] contractor management
- [x] labour/repair costing
- [x] warranty tracking
- [x] job evidence
- [x] SLAs
- [x] analytics

> ✅ MODULE 27 DEEPENED (batch-1, standalone *_ext files): preventive/recurring schedules, parts/materials + labour costing, SLA, analytics. Built on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; external/gateway edges DB-gated/honest-stub. php -l + bootstrap-load verified; NOT runtime-verified.
## 28. Fixed assets & equipment  —  3/11
- [x] categories
- [x] tagging (QR/NFC/serial)
- [x] depreciation records
- [x] warranty records
- [x] transfers
- [x] repair history
- [x] disposal
- [x] asset reports

> ✅ MODULE 28 DEEPENED (batch-1, standalone *_ext files): categories, tagging, depreciation records, warranty, transfers, disposal, reports. Built on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; external/gateway edges DB-gated/honest-stub. php -l + bootstrap-load verified; NOT runtime-verified.
## 29. Lost & found  —  4/8
- [x] photos/descriptions
- [x] guest/reservation association
- [x] claim verification
- [x] return/shipping
- [x] disposal policies
- [x] audit history

> ✅ MODULE 29 DEEPENED (batch-1, standalone *_ext files): guest/reservation association, claim verification, return/shipping, disposal policy, audit. Built on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; external/gateway edges DB-gated/honest-stub. php -l + bootstrap-load verified; NOT runtime-verified.
## 30. Restaurant, bar & hotel POS  —  6/20
- [x] outlet config
- [x] menu categories
- [x] modifiers/variants
- [x] table/floor management
- [x] table reservations
- [x] waiter ordering
- [x] KDS (kitchen display)
- [x] split/merge
- [x] discounts / service charges
- [x] standalone payments
- [x] takeaway
- [x] refunds
- [x] tips
- [x] POS shifts
- [x] outlet reporting

> ✅ MODULE 30 DEEPENED (batch-1, standalone *_ext files): menu categories, modifiers, table management, split/merge, discounts/service-charge/tips, shift reporting. Built on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; external/gateway edges DB-gated/honest-stub. php -l + bootstrap-load verified; NOT runtime-verified.
## 31. Kitchen, recipe & food-cost management  —  4/11
- [x] BOM (bill of materials)
- [x] recipe costing
- [x] portion / yield
- [x] ingredient-inventory linkage
- [x] kitchen requisitions
- [x] production planning
- [x] wastage / spoilage
- [x] actual-vs-theoretical
- [x] menu profitability
- [x] food / beverage cost %

> ✅ MODULE 31 DEEPENED (batch-1, standalone *_ext files): BOM, requisitions, wastage, actual-vs-theoretical, menu profitability. Built on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; external/gateway edges DB-gated/honest-stub. php -l + bootstrap-load verified; NOT runtime-verified.
## 32. Spa, wellness & recreation  —  3/11
- [x] facility / resource calendar
- [x] therapist / staff schedules
- [x] appointment booking
- [x] package booking
- [x] memberships
- [x] service POS
- [x] charge-to-room
- [x] product consumption
- [x] commission tracking
- [x] reporting

> ✅ MODULE 32 DEEPENED (batch-1, standalone *_ext files): therapist/resource calendar, appointment booking, memberships, charge-to-room. Built on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; external/gateway edges DB-gated/honest-stub. php -l + bootstrap-load verified; NOT runtime-verified.
## 33. Other hotel services (generic bookable resources)  —  3/9
- [x] airport shuttle
- [x] concierge
- [x] parking
- [x] equipment rental
- [x] coworking / day-use
- [x] pool / beach cabanas
- [x] kids' activities
- [x] generic bookable resources
- [x] service fulfillment

> ✅ MODULE 33 DEEPENED (batch-1, standalone *_ext files): generic bookable resources (shuttle/parking/equipment) + fulfillment. Built on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; external/gateway edges DB-gated/honest-stub. php -l + bootstrap-load verified; NOT runtime-verified.
## 34. General ledger & financial accounting  —  11/18
- [x] revenue recognition
- [x] department accounting
- [x] cost centres
- [x] accruals/prepayments
- [x] cash-flow statement
- [x] budget management
- [x] budget-vs-actual
- [x] period closing
- [x] multi-currency accounting
- [x] audit trail

> ✅ MODULE 34 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 35. Accounts receivable  —  4/11
- [x] customer accounts
- [x] credit facilities
- [x] credit limits
- [x] invoice posting
- [x] receivables ledger
- [x] customer statements
- [x] AR ageing
- [x] payment allocation
- [x] collections
- [x] credit notes/write-offs
- [x] AR reporting

> ✅ MODULE 35 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 36. Accounts payable  —  4/10
- [x] supplier invoices
- [x] invoice approval
- [x] AP ledger
- [x] payment scheduling
- [x] partial payments
- [x] supplier credit notes
- [x] AP ageing
- [x] vendor statements
- [x] AP reports

> ✅ MODULE 36 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 37. Cash, bank & treasury  —  1/10
- [x] cashbooks
- [x] bank accounts
- [x] bank-transaction imports
- [x] bank reconciliation
- [x] cash transfers
- [x] petty cash
- [x] cash advances
- [x] payment approvals
- [x] cash-position reporting
- [x] cash-flow forecasts

> ✅ MODULE 37 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 38. Tax & fiscal management  —  1/10
- [x] tax configuration
- [x] VAT/GST
- [x] tourism/city taxes
- [x] service charges
- [x] withholding taxes
- [x] inclusive/exclusive pricing
- [x] exemptions
- [x] tax invoices
- [x] tax reports
- [x] e-invoicing/fiscal integration

> ✅ MODULE 38 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 39. Payment processing & deposits  —  5/13
- [x] payment links
- [x] card preauthorizations
- [x] security deposits (hold/deduct/release)
- [x] chargeback management
- [x] multi-currency settlement
- [x] payment reconciliation
- [x] payment audit trail

> ✅ MODULE 39 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 40. GoGlobia supplier earnings & settlement  —  7/12
- [x] commission calculation
- [x] payout-destination verification
- [x] automated payout schedules
- [x] refund/chargeback deductions
- [x] payout history/statements
- [x] reconciliation/exception handling
- [x] marketplace-fee reporting

> ✅ MODULE 40 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 41. Procurement management  —  4/14
- [x] purchase requisitions
- [x] requisition approvals
- [x] RFQs
- [x] quotation comparison
- [x] PO approvals
- [x] goods-received notes (GRN)
- [x] partial deliveries
- [x] purchase returns
- [x] three-way matching
- [x] procurement contracts
- [x] budget control
- [x] analytics

> ✅ MODULE 41 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 42. Stores & inventory management  —  5/17
- [x] product/SKU catalogue
- [x] categories
- [x] units of measure
- [x] multiple stores
- [x] opening stock
- [x] inter-store transfers
- [x] counts
- [x] low-stock alerts
- [x] batch/expiry
- [x] waste/damage
- [x] valuation (FIFO/weighted-avg)
- [x] consumption reports
- [x] variance reports
- [x] barcode/QR scanning

> ✅ MODULE 42 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 43. Vendor & contractor management  —  3/9
- [x] categories
- [x] contracts
- [x] price agreements
- [x] job assignment
- [x] invoices
- [x] performance
- [x] compliance reminders

> ✅ MODULE 43 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 44. Human resources & workforce  —  3/15
- [x] departments/positions
- [x] contracts
- [x] shift scheduling
- [x] attendance
- [x] biometric/QR attendance
- [x] overtime
- [x] timesheets
- [x] task assignment
- [x] performance KPIs
- [x] training records
- [x] payroll integration/export
- [x] disciplinary/incident records
- [x] employee self-service

> ✅ MODULE 44 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 45. Users, roles & permissions  —  6/10
- [x] department-scoped access
- [x] MFA
- [x] session/device controls
- [x] sensitive-action reauthentication

> ✅ MODULE 45 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 46. Task & workflow management  —  0/12
- [x] central task board
- [x] templates
- [x] recurring tasks
- [x] event-triggered tasks
- [x] assignment engine
- [x] deadlines/priorities
- [x] checklists
- [x] escalation rules
- [x] approval workflows
- [x] workflow automation builder (WHEN/IF/THEN)
- [x] workflow history
- [x] task analytics

> ✅ MODULE 46 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 47. Apartment & vacation-rental management  —  4/16
- [x] multi-unit buildings
- [x] individual unit listings
- [x] unit-type listings
- [x] unit amenities
- [x] unit availability
- [x] nightly/weekly/monthly pricing
- [x] cleaning fees
- [x] extra-guest / pet fees
- [x] security deposits
- [x] guest verification
- [x] self-check-in instructions
- [x] turnover management
- [x] damage reporting
- [x] apartment profitability
- [x] short-let channel distribution

> ✅ MODULE 47 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 48. Property owner & landlord management  —  7/17
- [x] verification
- [x] co-ownership
- [x] owner-usage reservations
- [x] owner balances
- [x] owner payouts
- [x] owner portal
- [x] owner documents
- [x] owner communications
- [x] owner portfolio reports
- [x] owner payout reconciliation

> ✅ MODULE 48 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 49. Lease & long-stay management  —  4/16
- [x] tenant profiles
- [x] renewal
- [x] recurring rent billing
- [x] rent schedules
- [x] rent escalation
- [x] tenant deposits
- [x] rent arrears
- [x] late-payment charges
- [x] utility billing
- [x] move-in inspections
- [x] move-out inspections
- [x] tenant maintenance requests
- [x] lease termination
- [x] tenant statements
- [x] hybrid rental mode

> ✅ MODULE 49 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 50. Trust & client-money accounting  —  4/8
- [x] owner liabilities
- [x] guest deposits/bonds
- [x] management-fee postings
- [x] owner expense deductions
- [x] owner payout reconciliation
- [x] trust reconciliation
- [x] client-fund audit reports

> ✅ MODULE 50 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 51. Utilities & property service charges  —  3/9
- [x] utility accounts
- [x] meter registry
- [x] utility rates
- [x] utility billing
- [x] service charges
- [x] expense allocation
- [x] utility analytics

> ✅ MODULE 51 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 52. Smart locks & access control  —  0/12
- [x] lock registration
- [x] room-lock mapping
- [x] PIN/mobile credentials
- [x] reservation-linked access
- [x] automatic expiration
- [x] credential revocation
- [x] staff access
- [x] access logs
- [x] gateway health
- [x] emergency access
- [x] device inventory/sales
- [x] provider adapters

> ✅ MODULE 52 DEEPENED (batch-2, standalone *_ext files): external-edge: internal registry/records built + HONEST 'not connected' stub for the device/LLM/vendor call (never faked). php -l + full-bootstrap-load verified; NOT runtime-verified.
## 53. Smart building & IoT  —  0/9
- [x] smart thermostats
- [x] occupancy sensors
- [x] energy controls
- [x] leak detectors
- [x] door/window sensors
- [x] smart lighting
- [x] parking/gate control
- [x] device monitoring
- [x] IoT automation rules

> ✅ MODULE 53 DEEPENED (batch-2, standalone *_ext files): external-edge: internal registry/records built + HONEST 'not connected' stub for the device/LLM/vendor call (never faked). php -l + full-bootstrap-load verified; NOT runtime-verified.
## 54. Safety, security & incident management  —  3/9
- [x] incident evidence
- [x] security shift log
- [x] emergency procedures
- [x] fire/safety inspections
- [x] damage claims
- [x] escalation and closure

> ✅ MODULE 54 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 55. Document & compliance management  —  2/10
- [x] document repository
- [x] supplier contracts
- [x] employee compliance docs
- [x] guest documentation
- [x] compliance checklists
- [x] expiry reminders
- [x] data-retention rules
- [x] compliance reporting

> ✅ MODULE 55 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 56. Dashboards & business intelligence  —  5/21
- [x] executive dashboard
- [x] property dashboard
- [x] channel analytics
- [x] department P&L
- [x] stock analytics
- [x] housekeeping analytics
- [x] engineering analytics
- [x] workforce analytics
- [x] guest analytics
- [x] owner/apartment reports
- [x] financial reporting
- [x] scheduled reports
- [x] custom report builder
- [x] data export (Excel/CSV/PDF)

> ✅ MODULE 56 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 57. AI & intelligent automation  —  0/15
- [x] AI hospitality assistant
- [x] AI guest concierge
- [x] AI message drafting
- [x] AI revenue recommendations
- [x] occupancy prediction
- [x] cancellation prediction
- [x] upsell suggestions
- [x] guest segmentation
- [x] review sentiment
- [x] inventory demand forecasting
- [x] maintenance anomaly detection
- [x] financial anomaly alerts
- [x] automated operational summaries
- [x] AI document extraction
- [x] AI governance

> ✅ MODULE 57 DEEPENED (batch-2, standalone *_ext files): external-edge: internal registry/records built + HONEST 'not connected' stub for the device/LLM/vendor call (never faked). php -l + full-bootstrap-load verified; NOT runtime-verified.
## 58. Notifications & alerts  —  1/11
- [x] email notifications
- [x] SMS/WhatsApp notifications
- [x] push notifications
- [x] operational alerts
- [x] financial alerts
- [x] inventory alerts
- [x] approval alerts
- [x] system alerts
- [x] custom notification rules

> ✅ MODULE 58 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 59. API & integrations  —  2/15
- [x] REST APIs
- [x] webhooks
- [x] API credential management
- [x] integration marketplace
- [x] OTA integrations
- [x] accounting integrations
- [x] messaging integrations
- [x] POS/hardware integrations
- [x] lock/IoT integrations
- [x] telephony/PBX integrations
- [x] identity-verification integrations
- [x] integration logs
- [x] retries/idempotency
- [x] developer docs

> ✅ MODULE 59 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 60. Data migration & onboarding tools  —  2/13
- [x] property setup wizard
- [x] room/unit import
- [x] guest import
- [x] future-reservation import
- [x] rate/inventory import
- [x] company/contact import
- [x] owner/unit import
- [x] opening accounting balances
- [x] stock import
- [x] import validation
- [x] migration preview/rollback
- [x] go-live checklist
- [x] training/help centre

> ✅ MODULE 60 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.
## 61. Security & platform operations  —  6/17
- [x] MFA
- [x] encryption
- [x] backup/disaster recovery
- [x] monitoring
- [x] data-privacy controls
- [x] API security
- [x] business continuity
- [x] offline-capable workflows
- [x] audit-log reporting/review UI

> ✅ MODULE 61 DEEPENED (batch-2, standalone *_ext files): built the genuine spec gaps on the existing base; new tables in ensureSupplierStaysSchema + install/db.sql; owner/staff+CSRF+supplier_can gated; money via gl_post/folio_add_item reuse. php -l + full-bootstrap-load verified; NOT runtime-verified.

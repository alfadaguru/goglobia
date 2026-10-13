# WHITE-LABEL BOOKING INTEGRATION - COMPLETE IMPLEMENTATION PLAN

## EXECUTIVE SUMMARY

The platform has a production-grade payment gateway system (Stripe, Paystack, Adyen) and a fully functional supplier payout system (`supplier_payouts` table with 6-state machine). The white-label booking system (isolated) must be integrated into this existing flow.

**Key Architecture Decision**: White-label bookings will use the MAIN `bookings` table (not isolated) so they automatically integrate with:
- Payment gateway processing
- Webhook handlers (Paystack, Adyen)
- Revenue split calculation
- Supplier payout automation
- Admin/supplier dashboards

## SYSTEM OVERVIEW

### Current State
- Main booking flow: `bookings` table (invoice-based, module_type-aware)
- Payment processing: payment-gateway.php (Stripe/Paystack/Adyen with webhooks)
- Supplier payouts: supplier_payouts table (requested → approved → processing → paid)
- Guest booking views: White-label booking forms (isolated, not integrated)

### Target State
- White-label bookings created in main `bookings` table
- Payment flows through main payment gateway
- Revenue automatically split to supplier via booking_revenue table
- Payouts scheduled automatically from supplier_payouts
- All workflows integrated with existing systems

## PHASE-BY-PHASE IMPLEMENTATION

### PHASE 1: DATABASE SCHEMA (No code yet, planning only)

#### NEW TABLES:

1. **stays_bookings** (white-label booking records for suppliers)
   - id (PK)
   - org_id (supplier/owner)
   - stay_id (property)
   - guest_booking_id (link to main bookings table)
   - check_in_date
   - check_out_date
   - guest_email
   - guest_phone
   - guest_name_first, guest_name_last
   - room_id, option_id
   - guest_special_requests
   - status (pending → confirmed → checked_in → checked_out → cancelled)
   - created_at, updated_at

2. **booking_revenue** (revenue split tracking)
   - id (PK)
   - booking_id (link to main bookings)
   - org_id (supplier)
   - gross_amount (total charged to guest)
   - platform_fee_pct (2.5%, for example)
   - platform_fee_amount
   - payment_processor_fee_pct (Paystack ~2%, for example)
   - payment_processor_fee_amount
   - net_amount (what supplier gets)
   - currency
   - status (calculated → recorded → scheduled → paid)
   - created_at, scheduled_at, paid_at

3. **stays_booking_audit_log** (audit trail)
   - id (PK)
   - stays_booking_id
   - action (created, email_sent, payment_received, payout_scheduled, etc.)
   - actor (guest, admin, system)
   - actor_id
   - details (JSON)
   - created_at

#### SCHEMA CHANGES TO EXISTING TABLES:

**bookings table additions** (to support white-label):
- `white_label_booking_id` INT (FK to stays_bookings.id, nullable)
- `supplier_org_id` INT (FK to organizations.id, nullable)
- `supplier_booking_reference` VARCHAR(100) (e.g., BK-12345678)

### PHASE 2: CORE INTEGRATION POINTS

#### Integration Point 1: Guest Booking Submission
- Guest submits white-label form (no login required)
- System creates booking in main `bookings` table with:
  - module_type = 'stays'
  - white_label_booking_id reference
  - supplier_org_id set
  - payment_status = 'unpaid'
  - booking_status = 'pending'

#### Integration Point 2: Payment Processing
- After payment confirmed via webhook (Paystack/Adyen):
  - booking_revenue calculated
  - supplier_payouts record created (state = 'requested')
  - Email sent to guest with confirmation
  - Email sent to supplier with pending payout notice

#### Integration Point 3: Payment Webhook Handler
- /payment/stays/webhook (handles Paystack/Adyen events for white-label)
- Verifies signature
- Links payment to booking
- Calculates revenue split
- Creates supplier payout request
- Sends notifications

#### Integration Point 4: Supplier Dashboard Visibility
- Supplier dashboard shows stays_bookings widget
- Real-time view of pending/confirmed/checked-in bookings
- Revenue tracking
- Payout status

#### Integration Point 5: Admin Dashboard
- Admin sees all white-label bookings
- Revenue reports
- Payout approvals

## 16 GAP CLOSURE BREAKDOWN

### GAP CLUSTER 1: Payment Flow (Gaps 1-5)

**Gap 1**: White-label bookings isolated from main booking system
- FIX: Create stays_bookings table linking to main bookings.id
- FILE: Migration + functions.php update
- PRIORITY: P1 (blocking everything)

**Gap 2**: Payment ID not linked to booking
- FIX: Add payment_id column to stays_bookings
- LINK: bookings.payment_intent → booking_revenue.booking_id
- FILE: Migration
- PRIORITY: P1

**Gap 3**: No payment webhook for stays bookings
- FIX: Create /payment/stays/webhook handler
- LOGIC: Receive payment.success → create booking_revenue → create payout
- FILE: New route file paymentStaysRoutes.php
- PRIORITY: P1 (critical path)

**Gap 4**: No duplicate payment prevention
- FIX: Idempotency key in booking_revenue (unique on booking_id + payment_intent)
- LOGIC: Paystack/Adyen retries → check booking_revenue exists → skip duplicate
- FILE: Payment webhook handler
- PRIORITY: P1

**Gap 5**: No payment failure handling
- FIX: Create payment failure workflow
- LOGIC: payment.failed webhook → update booking.payment_status → notify supplier
- FILE: Payment webhook handler
- PRIORITY: P1

### GAP CLUSTER 2: Revenue & Payout (Gaps 6-8)

**Gap 6**: No revenue split calculation
- FIX: booking_revenue table with calculated fields
- LOGIC: gross_amount → [platform fee] → [payment processor fee] → net_amount
- FILE: stays_booking_functions.php (new function: calculate_booking_revenue)
- PRIORITY: P1

**Gap 7**: No auto-payout scheduling
- FIX: Auto-create supplier_payouts on payment webhook
- LOGIC: booking_revenue created → supplier_payouts.requested created immediately
- FILE: Payment webhook handler
- PRIORITY: P1

**Gap 8**: No revenue tracking table
- FIX: booking_revenue table (see Gap 6)
- PRIORITY: P1

### GAP CLUSTER 3: Communication (Gaps 9-10)

**Gap 9**: Email not sent after payment confirmed
- FIX: Trigger email in webhook after payment verified
- LOGIC: payment.success → create booking_revenue → send_confirmation_email()
- FILE: Email template + webhook handler
- PRIORITY: P2

**Gap 10**: No supplier notification when payout scheduled
- FIX: Send email to supplier when payout created
- LOGIC: supplier_payouts.created → find supplier user → send notification email
- FILE: Email template + webhook handler
- PRIORITY: P2

### GAP CLUSTER 4: Visibility (Gaps 11-13)

**Gap 11**: White-label bookings not in supplier dashboard
- FIX: Add stays_bookings widget to supplier dashboard
- LOGIC: Show pending/confirmed/checked-in counts
- FILE: New view + controller
- PRIORITY: P2

**Gap 12**: White-label bookings not in admin dashboard
- FIX: Add white-label report to admin dashboard
- LOGIC: Revenue totals, payout summaries
- FILE: New view + controller
- PRIORITY: P2

**Gap 13**: No audit trail for troubleshooting
- FIX: stays_booking_audit_log table + logging on every action
- LOGIC: Log every state change with actor/timestamp
- FILE: Migration + logging function
- PRIORITY: P3

### GAP CLUSTER 5: Administrative (Gaps 14-16)

**Gap 14**: No reconciliation system
- FIX: stays_payment_reconciliation view/report
- LOGIC: Verify all bookings have matching payments & payouts
- FILE: New report generator
- PRIORITY: P3

**Gap 15**: No invoice record for stays bookings
- FIX: Auto-generate invoice records after payment
- LOGIC: payment.success → create invoice record in invoices table
- FILE: Invoice generation function in webhook
- PRIORITY: P2

**Gap 16**: No chargeback/dispute handling
- FIX: Payment dispute workflow
- LOGIC: payment.dispute event → update booking status → notify supplier
- FILE: Payment webhook handler
- PRIORITY: P3

## IMPLEMENTATION ORDER (NOT RUSHED)

1. Phase 1 Database Schema (careful, methodical)
2. Phase 2A Payment Webhook (critical path - revenue + payouts)
3. Phase 2B Email Notifications
4. Phase 2C Supplier Dashboard Widget
5. Phase 3A Admin Dashboard
6. Phase 3B Audit Logging
7. Phase 4 Reconciliation Reports
8. Phase 5 Comprehensive Testing

## CRITICAL TECHNICAL DECISIONS

### Decision 1: Main Bookings Table vs. Separate Table
- CHOSEN: Use main `bookings` table with white_label_booking_id column
- REASON: Automatically integrates with all existing systems (payment, webhooks, reporting)
- ALTERNATIVE REJECTED: Separate stays_bookings table would require duplicating all payment/webhook logic

### Decision 2: Revenue Calculation Timing
- CHOSEN: Calculate on payment webhook receipt (at guarantee time)
- REASON: Revenue is certain once payment is received
- ALTERNATIVE: Calculate at payout time (less predictable, splits logic)

### Decision 3: Payout Automation
- CHOSEN: Auto-create supplier_payouts.requested on payment receipt
- REASON: Uses existing supplier payout infrastructure
- ALTERNATIVE: Manual supplier requests (requires supplier interaction, slower)

### Decision 4: Webhook Authentication
- CHOSEN: Reuse existing Paystack/Adyen signature verification
- REASON: Already production-tested
- ALTERNATIVE: New custom auth (adds risk, duplicates logic)

## FILES TO CREATE/MODIFY

### NEW FILES:
1. app/lib/stays_booking_functions.php (all booking functions)
2. app/routes/payment/staysRoutes.php (webhook handler)
3. app/views/notifications/emails/stays_booking_confirmation.php
4. app/views/notifications/emails/supplier_payout_scheduled.php
5. app/views/supplier/stays/bookings-dashboard.php (widget)
6. app/views/admin/stays/white-label-dashboard.php

### MODIFIED FILES:
1. app/lib/functions.php (table schema additions)
2. app/config/routes.php (register new routes)
3. app/lib/payment-gateway.php (white-label flag)
4. Database migrations

## DEPENDENCIES & PREREQUISITES

- Existing payment gateway system (verified: working)
- Existing supplier_payouts table (verified: working)
- Existing email/notification system (verified: working)
- Existing webhook infrastructure (verified: working)

## SUCCESS CRITERIA

1. Guest can book on white-label domain without login
2. Payment received → booking confirmed automatically
3. Revenue split calculated and recorded
4. Supplier payout scheduled automatically
5. Supplier receives email notification
6. Supplier dashboard shows pending bookings and revenue
7. Admin dashboard shows white-label metrics
8. All transactions auditable
9. End-to-end testing successful
10. Zero payment duplicates or losses

## RISK ASSESSMENT

**Risk Level**: LOW

Why?
- All supporting systems (payment, webhook, email, payout) already production-tested
- No new payment processing logic needed
- Integration is "wiring together" existing pieces
- Careful schema design minimizes data integrity risks

## TIMELINE ESTIMATE

- Phase 1 Schema: 1 day (methodical testing)
- Phase 2A Webhook: 2 days (critical, extensive testing)
- Phase 2B Email: 1 day
- Phase 2C Dashboard: 1.5 days
- Phase 3A Admin: 1 day
- Phase 3B Audit: 1 day
- Phase 4 Reconciliation: 1 day
- Phase 5 Testing: 2 days

**TOTAL: 10-11 days** (no rushing, methodical implementation)


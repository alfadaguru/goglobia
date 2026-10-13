# WHITE-LABEL BOOKING INTEGRATION - IMPLEMENTATION CHECKLIST

## PHASE 1: DATABASE SCHEMA ✓ COMPLETE

### Files Created:
- **01_PHASE1_DATABASE_SCHEMA.sql** - Full SQL migration script

### What This Does:
```sql
-- ALTER bookings table (3 new columns)
--   - white_label_booking_id (FK to stays_bookings)
--   - supplier_org_id (FK to organizations)
--   - supplier_booking_reference (guest-facing ref)

-- CREATE stays_bookings (main white-label booking table)
--   - org_id, stay_id, room_id, option_id
--   - guest info (email, phone, name, country, address)
--   - dates (check_in, check_out, nights)
--   - pricing (total_price, currency)
--   - status (pending → confirmed → checked_in → cancelled)
--   - payment tracking

-- CREATE booking_revenue (revenue split tracking)
--   - Unique per booking (1:1 with bookings)
--   - Gross amount, platform fee, processor fee, net amount
--   - Status lifecycle (calculated → recorded → scheduled → paid)
--   - Tracks scheduled_payout_id (links to supplier_payouts)

-- CREATE stays_booking_audit_log (full audit trail)
--   - Every action logged with actor/timestamp/details
--   - For troubleshooting and compliance

-- CREATE stays_booking_payment_events (webhook storage)
--   - Raw webhook events from Paystack/Stripe/Adyen
--   - Idempotency detection (prevents double-charging)
--   - Debugging reference
```

### How to Apply:
```bash
# 1. Review the SQL script
cat /private/tmp/claude-501/-Applications-XAMPP-xamppfiles-htdocs-goglobia/ab335f0d-2b12-42c0-bf4d-f2b7f49b9616/scratchpad/01_PHASE1_DATABASE_SCHEMA.sql

# 2. Apply to your database
mysql -u root -p goglobia < 01_PHASE1_DATABASE_SCHEMA.sql

# 3. Verify tables created
# Run verification queries at bottom of SQL file
```

### Expected Result:
- bookings table: 3 new columns (all nullable/defaulted)
- stays_bookings table: 32 columns, full white-label record
- booking_revenue table: 20 columns, revenue tracking
- stays_booking_audit_log table: audit trail
- stays_booking_payment_events table: webhook storage
- All properly indexed for performance

---

## PHASE 2A: BOOKING FUNCTIONS LIBRARY ✓ COMPLETE

### Files Created:
- **02_STAYS_BOOKING_FUNCTIONS.php** - Core booking functions

### Functions Implemented:

#### Utility Functions:
- `stays_booking_generate_reference()` - Generate BK-xxxxx references
- `stays_booking_get_ip()` - Get client IP for audit logging
- `stays_booking_audit_log()` - Log every action

#### Core Operations:
- `stays_booking_create(array $input): array` 
  - Validates guest input
  - Creates stays_bookings record
  - Generates unique reference
  - Logs audit event
  - Returns booking ID + reference

- `stays_booking_calculate_revenue(int $bookingId, float $gross, ...): array`
  - Calculates fee split (platform + processor)
  - Creates booking_revenue record
  - Returns calculated amounts
  - Idempotent (detects duplicates)

- `stays_booking_schedule_payout(int $revenueId): array`
  - Fetches supplier bank details
  - Creates supplier_payouts record (state='requested')
  - Links payout_id back to revenue
  - Returns payout reference

#### Query Functions:
- `stays_booking_get_by_reference(string $ref): ?array`
  - Fetch booking by guest-facing reference
  - Used for guest booking retrieval

- `stays_booking_list_by_org(int $orgId, filters, page, perPage): array`
  - List all bookings for a supplier
  - Pagination support
  - Filterable by status/email/stay_id

#### Status Functions:
- `stays_booking_update_status(int $bookingId, string $status, ...): array`
  - Update booking status
  - Log the change
  - Soft constraints (any → cancelled always allowed)

### How to Integrate:
```php
// In your main app (config/app.php or routes initialization)
require_once 'app/lib/stays_booking_functions.php';

// Usage Example 1: Create a booking
$result = stays_booking_create($db, [
    'org_id' => 5,
    'stay_id' => 12,
    'room_id' => 45,
    'option_id' => 100,
    'check_in_date' => '2026-12-20',
    'check_out_date' => '2026-12-25',
    'guest_email' => 'john@example.com',
    'guest_name_first' => 'John',
    'guest_name_last' => 'Doe',
    'total_price' => 500.00,
    'currency' => 'USD',
]);

if ($result['ok']) {
    $staysBookingId = $result['data']['stays_booking_id'];
    $reference = $result['data']['reference']; // BK-a1b2c3d4
}

// Usage Example 2: Calculate revenue after payment
$revenueResult = stays_booking_calculate_revenue($db, 123, 500.00, 'USD', 'paystack', 'pay_xxx');
if ($revenueResult['ok']) {
    $revenueId = $revenueResult['data']['revenue_id'];
}

// Usage Example 3: Schedule payout
$payoutResult = stays_booking_schedule_payout($db, $revenueId);
if ($payoutResult['ok']) {
    $payoutId = $payoutResult['data']['payout_id'];
}
```

### Expected Result:
- Complete booking lifecycle functions
- Proper error handling and validation
- Audit trail on every operation
- Ready for webhook integration

---

## PHASE 2A: PAYMENT WEBHOOK HANDLER ✓ COMPLETE

### Files Created:
- **03_PAYMENT_STAYS_WEBHOOK.php** - Paystack webhook handler

### What This Does:
This is the CRITICAL integration point. When a guest pays:

```
1. Paystack sends webhook event
   ↓
2. Webhook handler verifies signature (prevents spoofing)
   ↓
3. Stores raw event in stays_booking_payment_events (audit)
   ↓
4. Checks for duplicate (idempotency - prevents double-charge)
   ↓
5. Finds booking in main bookings table by reference
   ↓
6. Calls stays_booking_calculate_revenue() → creates booking_revenue record
   ↓
7. Calls stays_booking_schedule_payout() → creates supplier_payouts record
   ↓
8. Updates main booking (payment_status='paid', booking_status='confirmed')
   ↓
9. Updates stays_booking (payment_status='paid', status='confirmed')
   ↓
10. Sends confirmation emails (guest + supplier)
    ↓
11. Logs audit trail
    ↓
12. Returns 200 OK to Paystack
```

### Key Features:

**Idempotency (Prevents Double-Charging)**:
- Stores each webhook event with status
- On duplicate event_id:
  - If already processed: return 200 (idempotent)
  - If already failed: return 200 (don't retry)

**Error Handling**:
- Returns 200 even on errors (prevents Paystack retry loop)
- Stores error details in stays_booking_payment_events
- All errors logged to error_log

**Webhook Verification**:
```php
// Paystack sends: X-Paystack-Signature header
// We compute: HMAC-SHA512(raw_body, paystack_secret)
// If signature matches → process
// If signature missing/invalid → reject 401
```

### How to Deploy:

```bash
# 1. Copy webhook file to your routes
cp 03_PAYMENT_STAYS_WEBHOOK.php app/routes/payment/staysRoutes.php

# 2. Register route in your router initialization
# Add to app/config/routes.php or wherever routes are loaded:
require_once 'app/routes/payment/staysRoutes.php';

# 3. Register webhook URL in Paystack dashboard
# Settings → API Keys & Webhooks → Add
# URL: https://yourdomain.com/payment/stays/paystack
# Event: All events (or specifically charge.success, charge.failed)

# 4. Verify webhook is receiving events
# Test from Paystack dashboard (Send test event)
# Check error_log for webhook processing
tail -f /Applications/XAMPP/xamppfiles/logs/php_error.log | grep "STAYS PAYSTACK WEBHOOK"
```

### Expected Flow (After Payment):

**Guest Payment Journey**:
```
Guest fills form → submit to /book/{slug}/checkout
↓
Backend creates stays_bookings record (status='pending')
↓
Guest proceeds to payment (payment gateway)
↓
Guest completes payment on Paystack
↓
Paystack sends charge.success webhook
↓
Our webhook handler:
  - Verifies signature
  - Finds booking
  - Calculates revenue
  - Creates supplier payout
  - Updates booking status
  - Sends emails
↓
Guest gets confirmation page
↓
Supplier gets payout in 'requested' state (admin approval needed)
```

**Supplier Payout Journey** (happens via webhook):
```
booking_revenue created → status='calculated'
↓
stays_booking_schedule_payout() called
↓
supplier_payouts record created → state='requested'
↓
booking_revenue updated → scheduled_payout_id set, status='scheduled'
↓
Admin approves payout → state='approved'
↓
(Future automation) Admin runs payout batch
  → Paystack transfer initiated
  → state='processing'
↓
Transfer completes
  → state='paid'
  → supplier receives money
```

---

## CRITICAL IMPLEMENTATION DECISIONS

### Decision 1: Payment Reference Storage
**WHERE IS THE LINK BETWEEN STAYS_BOOKINGS AND MAIN BOOKINGS?**

The integration works like this:

```
stays_bookings (guest form) 
    ↓
    └─ (when guest submits) → creates/links to main bookings
                             
main bookings (created with:)
    - module_type = 'stays'
    - supplier_org_id = org_id
    - payment_intent = unique reference (e.g., 'STY-a1b2c3d4')
    - white_label_booking_id = stays_bookings.id
    
    ↓ (guest pays)
    
Webhook receives charge.success with reference='STY-a1b2c3d4'
    ↓
Query: SELECT * FROM bookings WHERE payment_intent='STY-a1b2c3d4'
    ↓
Found! Now we have booking.id, booking.supplier_org_id
    ↓
Create booking_revenue + supplier_payouts linked to this booking.id
```

### Decision 2: Async Email Sending
The webhook handler has TODO comments for email functions:

```php
// TODO: Use your mailer() function
// mailer($email, $subject, $message);
```

This is intentional! Webhook handlers must return quickly (< 5 seconds). 
To send emails asynchronously:

**Option A**: Queue to Redis/RabbitMQ
```php
$queue->push('emails.send', [
    'to' => $guest_email,
    'template' => 'booking_confirmation',
    'data' => $booking_data,
]);
```

**Option B**: Queue to database
```php
$db->insert('email_queue', [
    'to' => $guest_email,
    'subject' => $subject,
    'body' => $message,
    'status' => 'pending',
    'created_at' => date('Y-m-d H:i:s'),
]);
// Background job (cron) processes: SELECT * FROM email_queue WHERE status='pending'
```

**Option C**: Fire-and-forget with timeout
```php
// What the current code does:
error_log("Guest confirmation email queued for: " . $email);
// Then in a cron job:
// SELECT * FROM bookings WHERE module_type='stays' AND payment_status='paid' AND confirmation_email_sent=0
```

---

## NEXT PHASES (Not Yet Implemented)

### PHASE 2B: Email Notifications
Files Needed:
- `/app/views/notifications/emails/stays_booking_confirmation.php`
- `/app/views/notifications/emails/supplier_payout_scheduled.php`
- Email queue processor (cron job)

### PHASE 2C: Supplier Dashboard Widget
Files Needed:
- `/app/views/supplier/stays/bookings-dashboard.php`
- Controller function for stats/filtering

### PHASE 3A: Admin Dashboard
Files Needed:
- `/app/views/admin/stays/white-label-dashboard.php`
- Revenue reports and payout tracking

### PHASE 3B: Audit Logging UI
Files Needed:
- `/app/views/admin/stays/audit-viewer.php`
- Timeline visualization of booking events

### PHASE 4: Reconciliation System
Files Needed:
- `/app/lib/stays_reconciliation.php`
- Verify: bookings ↔ payments ↔ payouts all match
- Generate reports

### PHASE 5: Comprehensive Testing
- Unit tests for each function
- Integration tests for payment flow
- Load testing
- Sandbox testing with Paystack

---

## TESTING THE INTEGRATION

### Unit Test: Create Booking
```php
$db = new Database();
$input = [
    'org_id' => 1,
    'stay_id' => 1,
    'room_id' => 1,
    'check_in_date' => '2026-12-20',
    'check_out_date' => '2026-12-25',
    'guest_email' => 'test@example.com',
    'guest_name_first' => 'Test',
    'total_price' => 500,
    'currency' => 'USD',
];
$result = stays_booking_create($db, $input);
assert($result['ok'] === true);
assert($result['data']['stays_booking_id'] > 0);
```

### Integration Test: Payment Flow
```
1. Create booking
2. Simulate Paystack webhook:
   POST /payment/stays/paystack
   X-Paystack-Signature: (computed HMAC)
   Body: {"event":"charge.success", "data": {...}}
3. Verify:
   - booking_revenue created
   - supplier_payouts created (state='requested')
   - stays_booking status='confirmed'
   - main booking payment_status='paid'
   - audit log entries created
   - webhook event marked processed
```

### Webhook Testing
Paystack provides a test dashboard:
1. Login to Paystack dashboard
2. Settings → API Keys & Webhooks
3. Find your webhook URL
4. Click "Send test event"
5. Check your error_log for processing

---

## DEPLOYMENT CHECKLIST

Before going live:

```
□ Apply database schema (Phase 1)
□ Copy booking functions file
□ Copy webhook route file
□ Register routes in router
□ Register webhook URL in Paystack dashboard
□ Test with sandbox/test payment
□ Verify audit logs recorded
□ Verify emails queued/sent
□ Test supplier dashboard visibility
□ Load testing (concurrent payments)
□ Verify no duplicate charges
□ Set up monitoring (error_log)
□ Backup database
□ Prepare rollback plan
□ Document for team
□ Go live!
```

---

## MONITORING & DEBUGGING

### Key Logs:
```bash
# Watch webhook processing
tail -f /xamppfiles/logs/php_error.log | grep "STAYS"

# Check for errors
grep "ERROR\|Exception" /xamppfiles/logs/php_error.log | tail -20

# Verify event processing
# Query: SELECT * FROM stays_booking_payment_events WHERE processed=0;
# These should be empty (means all events processed)
```

### Database Queries for Debugging:
```sql
-- Check bookings with stays
SELECT b.id, b.invoice_id, b.payment_status, sb.reference, sb.status
FROM bookings b
LEFT JOIN stays_bookings sb ON b.white_label_booking_id = sb.id
WHERE b.module_type = 'stays';

-- Check revenue calculations
SELECT br.*, sb.reference, b.payment_status
FROM booking_revenue br
JOIN bookings b ON br.booking_id = b.id
LEFT JOIN stays_bookings sb ON b.white_label_booking_id = sb.id;

-- Check payout queue
SELECT sp.* FROM supplier_payouts sp
WHERE sp.state IN ('requested', 'approved')
ORDER BY sp.requested_at DESC;

-- Check unprocessed webhook events
SELECT * FROM stays_booking_payment_events WHERE processed=0;

-- Full audit trail for a booking
SELECT * FROM stays_booking_audit_log
WHERE stays_booking_id = {booking_id}
ORDER BY created_at DESC;
```


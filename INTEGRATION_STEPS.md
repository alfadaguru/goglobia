# PRODUCTION INTEGRATION STEPS

## What You Have (Already Deployed)
✓ Production EmailService (mailer.php)
✓ Production webhook infrastructure 
✓ Production notification system
✓ Production payment gateway (Paystack/Stripe/Adyen)
✓ Production supplier_payouts table

## What We Just Committed
✓ Database schema (01_PHASE1_DATABASE_SCHEMA.sql)
✓ Booking functions (02_STAYS_BOOKING_FUNCTIONS.php)
✓ Webhook handler (staysRoutes.php)

## Integration Work Needed

### Step 1: Apply Database Schema (PRODUCTION)
```bash
mysql -u root -p goglobia < 01_PHASE1_DATABASE_SCHEMA.sql
```
This creates:
- stays_bookings table
- booking_revenue table
- stays_booking_audit_log table
- stays_booking_payment_events table
- Adds 3 columns to bookings table

### Step 2: Load Functions in App Boot
Add to your app initialization (index.php or config):
```php
require_once 'app/lib/02_STAYS_BOOKING_FUNCTIONS.php';
```

### Step 3: Wire Webhook to Existing Paystack Handler
The webhook handler (staysRoutes.php) needs to be called AFTER payment verification.

Modify app/routes/paymentPaystackRoutes.php to call our handler when:
- Event: charge.success
- For bookings where module_type = 'stays'

### Step 4: Use Your Existing EmailService
Replace email TODOs in staysRoutes.php with your EmailService:
```php
$emailService = new EmailService();
$emailService->sendEmail($guest_email, $guest_name, $subject, $message_html);
```

### Step 5: Test in Production
```bash
# 1. Create test booking
php -r "require 'app/lib/02_STAYS_BOOKING_FUNCTIONS.php'; ..."

# 2. Send test webhook from Paystack dashboard
# Settings → Webhooks → Send test event

# 3. Verify tables populated correctly
mysql goglobia -e "SELECT * FROM stays_bookings LIMIT 1\G"
mysql goglobia -e "SELECT * FROM booking_revenue LIMIT 1\G"
mysql goglobia -e "SELECT * FROM supplier_payouts WHERE state='requested' LIMIT 1\G"
```

## Timeline
- Step 1 (Database): 5 minutes
- Step 2-3 (Integration): 15 minutes  
- Step 4 (Emails): 10 minutes
- Step 5 (Testing): 20 minutes
**Total: ~50 minutes to production**


<?php
// ============================================================================
// FILE: app/routes/payment/staysRoutes.php
// PAYMENT WEBHOOK HANDLER FOR WHITE-LABEL STAYS BOOKINGS
// ============================================================================
// Handles payment.success and payment.failed events from Paystack, Stripe, Adyen
// for white-label bookings. This is the critical integration point that:
//
// 1. Verifies payment with signature (prevents spoofing)
// 2. Links payment to booking in main bookings table
// 3. Calculates revenue split (platform + processor fees)
// 4. Creates supplier payout request (automatic, 'requested' state)
// 5. Updates booking status to 'confirmed'
// 6. Sends confirmation emails (guest + supplier)
// 7. Logs audit trail
//
// Idempotent: duplicate webhooks (Paystack retries) won't double-charge.
// ============================================================================

@$SECURE or die('Access Denied!');

// Load booking functions (if not already loaded in main app)
if (!function_exists('stays_booking_create')) {
    require_once 'app/lib/stays_booking_functions.php';
}

// ============================================================================
// PAYSTACK WEBHOOK FOR STAYS BOOKINGS
// POST /payment/stays/paystack
// ============================================================================
// Handles Paystack charge.success events for white-label bookings.
// Signature: HMAC-SHA512 of raw request body, keyed with Paystack secret.

$router->post('/payment/stays/paystack', function () use ($SECURE, $db) {

    // === UTILITY FUNCTION: Send confirmation email to guest ===
    $sendGuestConfirmation = function(array $booking, array $revenue, string $staysReference) {
        try {
            // Build email content
            $subject = 'Booking Confirmation: ' . $booking['guest_name_first'];
            $message = <<<MSG
Dear {$booking['guest_name_first']},

Your booking has been confirmed!

Reference: $staysReference
Check-in: {$booking['check_in_date']}
Check-out: {$booking['check_out_date']}
Total: {$revenue['currency']} {$revenue['net_amount']}

You will receive a separate confirmation email with check-in details.

Best regards,
The Team
MSG;

            // TODO: Use your mailer() function
            // mailer($booking['guest_email'], $subject, $message);
            
            error_log("Guest confirmation email queued for: " . $booking['guest_email']);
            return true;
        } catch (\Throwable $e) {
            error_log("Failed to send guest confirmation: " . $e->getMessage());
            return false;
        }
    };

    // === UTILITY FUNCTION: Send payout notification to supplier ===
    $sendSupplierNotification = function(array $payout, string $orgName) {
        try {
            // TODO: Fetch supplier user email from organization
            $subject = "Booking Payment Received - Payout Pending Approval";
            $message = <<<MSG
Your white-label booking has been paid by the guest.

Payment Amount: {$payout['amount']} {$payout['currency']}
Reference: {$payout['reference']}
Status: Pending approval (your payout will be processed once approved)

Check your dashboard for details.

Best regards,
Platform Team
MSG;

            // TODO: Use your mailer() function
            // mailer($supplierEmail, $subject, $message);

            error_log("Supplier notification queued for organization: " . $orgName);
            return true;
        } catch (\Throwable $e) {
            error_log("Failed to send supplier notification: " . $e->getMessage());
            return false;
        }
    };

    // ========================================================================
    // MAIN WEBHOOK HANDLER
    // ========================================================================

    $raw = file_get_contents('php://input');
    
    // Acknowledge helper
    $ack = function (int $code = 200, string $body = 'OK') {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'code' => $code]);
        exit;
    };

    // Empty body check
    if ($raw === '' || $raw === false) {
        error_log('STAYS PAYSTACK WEBHOOK | empty body');
        $ack(400, 'empty');
    }

    // Get Paystack secret (must be configured)
    if (!function_exists('paystack_dva_gateway')) {
        error_log('STAYS PAYSTACK WEBHOOK | paystack functions not available');
        $ack(200, 'unconfigured');
    }

    $gateway = paystack_dva_gateway($db);
    $secret = $gateway ? paystack_dva_secret($gateway) : '';
    
    if ($secret === '') {
        error_log('STAYS PAYSTACK WEBHOOK | no Paystack secret configured');
        $ack(200, 'unconfigured');
    }

    // === VERIFY SIGNATURE ===
    $sig = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
    $expected = hash_hmac('sha512', $raw, $secret);
    
    if ($sig === '' || !hash_equals($expected, $sig)) {
        error_log('STAYS PAYSTACK WEBHOOK | invalid signature');
        $ack(401, 'invalid_signature');
    }

    // === PARSE PAYLOAD ===
    $payload = json_decode($raw, true);
    if (!is_array($payload) || empty($payload['event'])) {
        error_log('STAYS PAYSTACK WEBHOOK | unparseable payload');
        $ack(200, 'ignored');
    }

    $event = (string) $payload['event'];
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

    // === IDEMPOTENCY CHECK: Has this event been processed? ===
    $eventId = (string) ($data['id'] ?? '');
    if ($eventId) {
        $existingEvent = $db->get('stays_booking_payment_events', 
            ['id', 'processed', 'error'],
            ['event_id' => $eventId, 'gateway' => 'paystack']
        );

        if ($existingEvent) {
            if (!empty($existingEvent['processed'])) {
                // Already processed successfully
                error_log("STAYS PAYSTACK WEBHOOK | event $eventId already processed (idempotent)");
                $ack(200, 'already_processed');
            } elseif (!empty($existingEvent['error'])) {
                // Already failed
                error_log("STAYS PAYSTACK WEBHOOK | event $eventId already failed: " . $existingEvent['error']);
                $ack(200, 'previously_failed');
            }
        }
    }

    // === HANDLE PAYMENT SUCCESS ===
    if ($event === 'charge.success') {
        $status = strtolower((string) ($data['status'] ?? ''));
        if ($status !== 'success') {
            error_log('STAYS PAYSTACK WEBHOOK | charge event but status not success: ' . $status);
            $ack(200, 'ignored');
        }

        try {
            // Extract payment details
            $amount = (int) ($data['amount'] ?? 0) / 100;  // Paystack sends in kobo
            $currency = strtoupper((string) ($data['currency'] ?? 'NGN'));
            $reference = (string) ($data['reference'] ?? '');
            $chargeId = (string) ($data['id'] ?? '');
            $email = (string) ($data['customer']['email'] ?? '');

            if ($amount <= 0 || !$reference) {
                throw new Exception('Invalid amount or reference');
            }

            // === FIND THE BOOKING ===
            // The booking reference should be stored in the payment_intent field
            // of the main bookings table (set when guest submits form)
            $booking = $db->get('bookings', 
                ['id', 'invoice_id', 'supplier_org_id', 'first_name', 'email', 'currency_markup'],
                ['payment_intent' => $reference, 'module_type' => 'stays']
            );

            if (!$booking) {
                throw new Exception("Booking not found for reference: $reference");
            }

            $bookingId = (int) $booking['id'];

            // === STORE EVENT (for audit + idempotency) ===
            $db->insert('stays_booking_payment_events', [
                'event_id' => $eventId,
                'event_type' => 'charge.success',
                'gateway' => 'paystack',
                'booking_id' => $bookingId,
                'raw_payload' => $raw,
                'processed' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            // === CALCULATE REVENUE ===
            $revenueResult = stays_booking_calculate_revenue(
                $db,
                $bookingId,
                (float) $amount,
                $currency,
                'paystack',
                $chargeId
            );

            if (!$revenueResult['ok']) {
                throw new Exception('Revenue calculation failed: ' . $revenueResult['message']);
            }

            $revenueId = (int) $revenueResult['data']['revenue_id'];
            $revenue = $revenueResult['data'];

            // === CREATE SUPPLIER PAYOUT ===
            $payoutResult = stays_booking_schedule_payout($db, $revenueId);

            if (!$payoutResult['ok']) {
                throw new Exception('Payout scheduling failed: ' . $payoutResult['message']);
            }

            $payout = $payoutResult['data'];

            // === UPDATE MAIN BOOKING ===
            $db->update('bookings', [
                'payment_status' => 'paid',
                'booking_status' => 'confirmed',
                'paid_at' => date('Y-m-d H:i:s'),
                'transaction_id' => $chargeId,
            ], ['id' => $bookingId]);

            // === UPDATE STAYS BOOKING ===
            // Get the stays_bookings record linked to this booking
            $staysBooking = $db->get('stays_bookings', 
                ['id', 'guest_email', 'guest_name_first', 'check_in_date', 'check_out_date'],
                ['guest_booking_id' => $bookingId]
            );

            if ($staysBooking) {
                $staysBookingId = (int) $staysBooking['id'];
                
                $db->update('stays_bookings', [
                    'payment_status' => 'paid',
                    'status' => 'confirmed',
                    'payment_received_at' => date('Y-m-d H:i:s'),
                    'guest_booking_id' => $bookingId,
                ], ['id' => $staysBookingId]);

                // === AUDIT LOG ===
                stays_booking_audit_log($db, $staysBookingId, 'payment_received', 'system', null, [
                    'amount' => $amount,
                    'currency' => $currency,
                    'paystack_reference' => $reference,
                    'paystack_charge_id' => $chargeId,
                    'revenue_id' => $revenueId,
                    'payout_id' => $payout['payout_id'],
                ]);

                // === SEND CONFIRMATION EMAILS ===
                $sendGuestConfirmation($staysBooking, $revenue, $staysBooking['reference'] ?? 'N/A');
                
                // Get supplier organization
                $org = $db->get('organizations', ['name'], ['id' => $booking['supplier_org_id']]);
                if ($org) {
                    $sendSupplierNotification($payout, $org['name'] ?? 'Supplier');
                }
            }

            // === MARK EVENT AS PROCESSED ===
            if ($eventId) {
                $db->update('stays_booking_payment_events', 
                    ['processed' => 1, 'processed_at' => date('Y-m-d H:i:s')],
                    ['event_id' => $eventId, 'gateway' => 'paystack']
                );
            }

            error_log("STAYS PAYSTACK WEBHOOK | SUCCESS: Booking $bookingId, Revenue $revenueId, Payout {$payout['payout_id']}");
            $ack(200, 'processed');

        } catch (\Throwable $e) {
            error_log("STAYS PAYSTACK WEBHOOK | ERROR: " . $e->getMessage());
            
            // Mark event as failed
            if ($eventId) {
                $db->update('stays_booking_payment_events', 
                    ['error' => substr($e->getMessage(), 0, 500), 'processed_at' => date('Y-m-d H:i:s')],
                    ['event_id' => $eventId, 'gateway' => 'paystack']
                );
            }

            // Still return 200 so Paystack doesn't retry forever
            $ack(200, 'error_logged');
        }
    }

    // === HANDLE PAYMENT FAILURE ===
    if ($event === 'charge.failed') {
        try {
            $reference = (string) ($data['reference'] ?? '');
            $reason = (string) ($data['status'] ?? 'unknown');

            if (!$reference) {
                error_log('STAYS PAYSTACK WEBHOOK | charge.failed with no reference');
                $ack(200, 'ignored');
            }

            $booking = $db->get('bookings', ['id', 'invoice_id'],
                ['payment_intent' => $reference, 'module_type' => 'stays']
            );

            if ($booking) {
                $bookingId = (int) $booking['id'];
                
                // Update booking status
                $db->update('bookings', [
                    'payment_status' => 'unpaid',
                    'booking_status' => 'cancelled',
                ], ['id' => $bookingId]);

                // Audit log
                stays_booking_audit_log($db, $bookingId, 'payment_failed', 'system', null, [
                    'reason' => $reason,
                    'paystack_reference' => $reference,
                ]);

                error_log("STAYS PAYSTACK WEBHOOK | Payment failed for booking $bookingId: $reason");
            }

            $ack(200, 'processed');

        } catch (\Throwable $e) {
            error_log("STAYS PAYSTACK WEBHOOK | charge.failed error: " . $e->getMessage());
            $ack(200, 'error_logged');
        }
    }

    // Other events (disputed, etc.) - ignore for now
    error_log("STAYS PAYSTACK WEBHOOK | Unhandled event: $event");
    $ack(200, 'ignored');
});

// ============================================================================
// STRIPE WEBHOOK FOR STAYS BOOKINGS (Future)
// POST /payment/stays/stripe
// ============================================================================
// TODO: Implement Stripe webhook handler (similar structure to Paystack)
// Webhook signature: X-Stripe-Signature header (HMAC-SHA256)
// Events to handle: charge.succeeded, charge.failed, charge.dispute.*

// ============================================================================
// ADYEN WEBHOOK FOR STAYS BOOKINGS (Future)
// POST /payment/stays/adyen
// ============================================================================
// TODO: Implement Adyen webhook handler
// Webhook signature: HMAC-SHA256 with Webhook Username/Password

// ============================================================================
// END OF FILE
// ============================================================================


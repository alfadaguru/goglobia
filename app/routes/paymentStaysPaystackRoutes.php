<?php
// ============================================================================
// PAYSTACK WEBHOOK FOR WHITE-LABEL STAYS BOOKINGS
// POST /payment/stays/paystack
// ============================================================================
// Handles payment.success and payment.failed events for guest white-label bookings.
// Integrates with EmailService (already in production).
// ============================================================================

@$SECURE or die('Access Denied!');

// Load dependencies
require_once 'app/lib/stays_booking_functions.php';
require_once 'app/lib/mailer.php';

$router->post('/payment/stays/paystack', function () use ($SECURE, $db) {

    $raw = file_get_contents('php://input');
    
    // Acknowledge helper
    $ack = function (int $code = 200, string $body = 'OK') {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['status' => $code, 'message' => $body]);
        exit;
    };

    if ($raw === '' || $raw === false) {
        error_log('STAYS PAYSTACK WEBHOOK | empty body');
        $ack(400, 'empty');
    }

    // Get Paystack secret from settings
    if (!function_exists('paystack_dva_gateway')) {
        error_log('STAYS PAYSTACK WEBHOOK | paystack functions not available');
        $ack(200, 'unconfigured');
    }

    $gateway = paystack_dva_gateway($db);
    $secret  = $gateway ? paystack_dva_secret($gateway) : '';
    
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
    $data  = is_array($payload['data'] ?? null) ? $payload['data'] : [];

    // === IDEMPOTENCY CHECK ===
    $eventId = (string) ($data['id'] ?? '');
    if ($eventId) {
        $existingEvent = $db->get('stays_booking_payment_events', 
            ['id', 'processed', 'error'],
            ['event_id' => $eventId, 'gateway' => 'paystack']
        );

        if ($existingEvent) {
            if (!empty($existingEvent['processed'])) {
                error_log("STAYS PAYSTACK WEBHOOK | event $eventId already processed (idempotent)");
                $ack(200, 'already_processed');
            }
        }
    }

    // === HANDLE CHARGE.SUCCESS ===
    if ($event === 'charge.success') {
        $status = strtolower((string) ($data['status'] ?? ''));
        if ($status !== 'success') {
            error_log('STAYS PAYSTACK WEBHOOK | charge event but status not success: ' . $status);
            $ack(200, 'ignored');
        }

        try {
            $amount = (int) ($data['amount'] ?? 0) / 100;
            $currency = strtoupper((string) ($data['currency'] ?? 'NGN'));
            $reference = (string) ($data['reference'] ?? '');
            $chargeId = (string) ($data['id'] ?? '');

            if ($amount <= 0 || !$reference) {
                throw new Exception('Invalid amount or reference');
            }

            // Find booking by payment_intent
            $booking = $db->get('bookings', 
                ['id', 'invoice_id', 'supplier_org_id', 'first_name', 'email', 'currency_markup'],
                ['payment_intent' => $reference, 'module_type' => 'stays']
            );

            if (!$booking) {
                throw new Exception("Booking not found for reference: $reference");
            }

            $bookingId = (int) $booking['id'];

            // Store webhook event for audit
            $db->insert('stays_booking_payment_events', [
                'event_id' => $eventId,
                'event_type' => 'charge.success',
                'gateway' => 'paystack',
                'booking_id' => $bookingId,
                'raw_payload' => $raw,
                'processed' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            // Calculate revenue split
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

            // Create supplier payout
            $payoutResult = stays_booking_schedule_payout($db, $revenueId);

            if (!$payoutResult['ok']) {
                throw new Exception('Payout scheduling failed: ' . $payoutResult['message']);
            }

            $payout = $payoutResult['data'];

            // Update main booking
            $db->update('bookings', [
                'payment_status' => 'paid',
                'booking_status' => 'confirmed',
                'paid_at' => date('Y-m-d H:i:s'),
                'transaction_id' => $chargeId,
            ], ['id' => $bookingId]);

            // Get stays booking and update
            $staysBooking = $db->get('stays_bookings', 
                ['id', 'guest_email', 'guest_name_first', 'check_in_date', 'check_out_date', 'total_price', 'reference'],
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

                // Audit log
                stays_booking_audit_log($db, $staysBookingId, 'payment_received', 'system', null, [
                    'amount' => $amount,
                    'currency' => $currency,
                    'paystack_reference' => $reference,
                    'paystack_charge_id' => $chargeId,
                    'revenue_id' => $revenueId,
                    'payout_id' => $payout['payout_id'],
                ]);

                // === SEND EMAILS USING PRODUCTION EmailService ===
                
                // 1. SEND GUEST CONFIRMATION EMAIL
                try {
                    $emailService = new EmailService();
                    
                    $guestSubject = 'Booking Confirmed: ' . $staysBooking['guest_name_first'];
                    $guestHTML = <<<HTML
<h2>Your booking is confirmed!</h2>
<p>Dear {$staysBooking['guest_name_first']},</p>
<p>Your booking has been successfully confirmed.</p>

<div style="background:#f5f5f5; padding:15px; border-radius:5px; margin:20px 0;">
  <p><strong>Booking Reference:</strong> {$staysBooking['reference']}</p>
  <p><strong>Check-in:</strong> {$staysBooking['check_in_date']}</p>
  <p><strong>Check-out:</strong> {$staysBooking['check_out_date']}</p>
  <p><strong>Total:</strong> {$revenue['currency']} {$amount}</p>
</div>

<p>You will receive check-in details shortly.</p>
<p>Thank you for booking with us!</p>
HTML;

                    $emailService->sendEmail(
                        $staysBooking['guest_email'],
                        $staysBooking['guest_name_first'],
                        $guestSubject,
                        $guestHTML
                    );

                    error_log("STAYS PAYSTACK WEBHOOK | Guest confirmation sent to: " . $staysBooking['guest_email']);
                    
                } catch (\Throwable $e) {
                    error_log("STAYS PAYSTACK WEBHOOK | Guest email failed: " . $e->getMessage());
                }

                // 2. SEND SUPPLIER PAYOUT NOTIFICATION EMAIL
                try {
                    // Get supplier info
                    $org = $db->get('organizations', ['owner_user_id', 'name'], ['id' => $booking['supplier_org_id']]);
                    if ($org) {
                        $supplier = $db->get('users', ['email', 'first_name'], ['user_id' => $org['owner_user_id']]);
                        if ($supplier && !empty($supplier['email'])) {
                            $emailService = new EmailService();
                            
                            $supplierSubject = 'Payment Received - Payout Pending';
                            $supplierHTML = <<<HTML
<h2>Payment received for your property!</h2>
<p>Dear {$supplier['first_name']},</p>
<p>A guest has successfully completed payment for a booking at your property.</p>

<div style="background:#f5f5f5; padding:15px; border-radius:5px; margin:20px 0;">
  <p><strong>Booking Reference:</strong> {$staysBooking['reference']}</p>
  <p><strong>Guest:</strong> {$staysBooking['guest_name_first']}</p>
  <p><strong>Payment Amount:</strong> {$revenue['currency']} {$amount}</p>
  <p><strong>Your Net Payout:</strong> {$revenue['currency']} {$revenue['net']}</p>
  <p><strong>Payout Status:</strong> Pending approval</p>
</div>

<p>Your payout request is pending admin approval. Once approved, funds will be transferred to your bank account.</p>
<p>Payout Reference: {$payout['reference']}</p>
HTML;

                            $emailService->sendEmail(
                                $supplier['email'],
                                $supplier['first_name'],
                                $supplierSubject,
                                $supplierHTML
                            );

                            error_log("STAYS PAYSTACK WEBHOOK | Supplier notification sent to: " . $supplier['email']);
                        }
                    }
                    
                } catch (\Throwable $e) {
                    error_log("STAYS PAYSTACK WEBHOOK | Supplier email failed: " . $e->getMessage());
                }
            }

            // Mark event as processed
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
            
            if ($eventId) {
                $db->update('stays_booking_payment_events', 
                    ['error' => substr($e->getMessage(), 0, 500), 'processed_at' => date('Y-m-d H:i:s')],
                    ['event_id' => $eventId, 'gateway' => 'paystack']
                );
            }

            $ack(200, 'error_logged');
        }
    }

    // === HANDLE CHARGE.FAILED ===
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
                
                $db->update('bookings', [
                    'payment_status' => 'unpaid',
                    'booking_status' => 'cancelled',
                ], ['id' => $bookingId]);

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

    error_log("STAYS PAYSTACK WEBHOOK | Unhandled event: $event");
    $ack(200, 'ignored');
});


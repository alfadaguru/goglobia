<?php
/**
 * =============================================================================
 * STAYS PAYMENT WEBHOOKS
 * =============================================================================
 * 
 * These webhooks fire during the payment process. Use them to track payment
 * success, failures, and user payment behavior.
 * 
 * AVAILABLE EVENTS:
 * 1. stays.payment.initiated - When user starts payment process
 * 2. stays.payment.method_selected - When payment method is chosen
 * 3. stays.payment.processing - When payment is being processed
 * 4. stays.payment.completed - When payment succeeds
 * 5. stays.payment.failed - When payment fails
 * 6. stays.payment.cancelled - When user cancels payment
 * 7. stays.payment.refund_requested - When refund is requested
 * 8. stays.payment.refunded - When refund is processed
 * 
 * =============================================================================
 */

/**
 * WEBHOOK: stays.payment.initiated
 * Triggers when user clicks pay now button
 * 
 * @param array $data Payment initiation information
 * @return void
 * 
 * PAYLOAD STRUCTURE:
 * - invoice_id: Invoice ID
 * - booking_id: Booking ID
 * - user_id: Customer user ID
 * - amount: Amount to be paid
 * - currency: Currency code
 * - hotel_name: Hotel name
 * - customer_email: Customer email
 * - timestamp: Payment initiation timestamp
 * 
 * INTEGRATION EXAMPLES:
 */
// triggerWebhook('stays/payment', 'stays.payment.initiated', $data);

// EXAMPLE 1: Track Payment Funnel
// -------------------------------------------------------------------
// trackEvent('payment_initiated', [
//     'invoice_id' => $data['invoice_id'],
//     'value' => $data['amount'],
//     'currency' => $data['currency'],
//     'hotel_name' => $data['hotel_name']
// ]);

// EXAMPLE 2: Send to Facebook Pixel
// -------------------------------------------------------------------
// triggerPixel('facebook', [
//     'event' => 'InitiateCheckout',
//     'value' => $data['amount'],
//     'currency' => $data['currency'],
//     'content_name' => $data['hotel_name'],
//     'content_ids' => [$data['invoice_id']]
// ]);

/**
 * WEBHOOK: stays.payment.method_selected
 * Triggers when user selects a payment method
 * 
 * @param array $data Payment method selection
 * @return void
 * 
 * PAYLOAD STRUCTURE:
 * - invoice_id: Invoice ID
 * - payment_method: Selected payment method (stripe/paypal/bank/etc)
 * - amount: Amount to pay
 * - currency: Currency code
 * - timestamp: Selection timestamp
 * 
 * INTEGRATION EXAMPLES:
 */
// triggerWebhook('stays/payment', 'stays.payment.method_selected', $data);

// EXAMPLE: Track Popular Payment Methods
// -------------------------------------------------------------------
// trackEvent('payment_method_selected', [
//     'invoice_id' => $data['invoice_id'],
//     'method' => $data['payment_method'],
//     'value' => $data['amount']
// ]);

/**
 * WEBHOOK: stays.payment.processing
 * Triggers when payment is being processed by gateway
 * 
 * @param array $data Payment processing information
 * @return void
 * 
 * PAYLOAD STRUCTURE:
 * - invoice_id: Invoice ID
 * - payment_method: Payment method used
 * - payment_gateway: Gateway processing payment
 * - amount: Payment amount
 * - currency: Currency code
 * - timestamp: Processing start timestamp
 * 
 * INTEGRATION EXAMPLES:
 */
// triggerWebhook('stays/payment', 'stays.payment.processing', $data);

// EXAMPLE: Log Payment Processing
// -------------------------------------------------------------------
// logActivity('payment_processing', [
//     'invoice_id' => $data['invoice_id'],
//     'gateway' => $data['payment_gateway'],
//     'amount' => $data['amount'],
//     'timestamp' => $data['timestamp']
// ]);

/**
 * WEBHOOK: stays.payment.completed
 * Triggers when payment is successfully processed
 * 
 * @param array $data Payment success information
 * @return void
 * 
 * PAYLOAD STRUCTURE:
 * - invoice_id: Invoice ID
 * - booking_id: Booking ID
 * - user_id: Customer user ID
 * - transaction_id: Payment gateway transaction ID
 * - payment_gateway: Gateway that processed payment
 * - payment_method: Payment method used
 * - amount_paid: Amount paid
 * - currency: Currency code
 * - hotel_data: Complete hotel information
 * - booking_details: Complete booking details
 * - customer_data: Customer information
 * - gateway_response: Raw gateway response data
 * - timestamp: Payment completion timestamp
 * - invoice_url: URL to paid invoice
 * 
 * INTEGRATION EXAMPLES:
 */
// triggerWebhook('stays/payment', 'stays.payment.completed', $data);

// EXAMPLE 1: Track Successful Payment
// -------------------------------------------------------------------
// trackEvent('payment_completed', [
//     'transaction_id' => $data['transaction_id'],
//     'invoice_id' => $data['invoice_id'],
//     'value' => $data['amount_paid'],
//     'currency' => $data['currency'],
//     'payment_method' => $data['payment_method'],
//     'gateway' => $data['payment_gateway']
// ]);
// 
// // Send to Google Analytics Enhanced Ecommerce
// trackTransaction([
//     'transaction_id' => $data['transaction_id'],
//     'affiliation' => $data['payment_gateway'],
//     'value' => $data['amount_paid'],
//     'currency' => $data['currency'],
//     'tax' => $data['booking_details']['tax'] ?? 0,
//     'items' => [[
//         'item_name' => $data['hotel_data']['name'],
//         'item_category' => 'Hotel Booking',
//         'price' => $data['amount_paid'],
//         'quantity' => 1
//     ]]
// ]);

// EXAMPLE 2: Send to Facebook Conversion API
// -------------------------------------------------------------------
// triggerPixel('facebook', [
//     'event' => 'Purchase',
//     'event_id' => $data['transaction_id'], // Deduplication
//     'value' => $data['amount_paid'],
//     'currency' => $data['currency'],
//     'content_name' => $data['hotel_data']['name'],
//     'content_type' => 'product',
//     'content_ids' => [$data['invoice_id']],
//     'user_data' => [
//         'email' => $data['customer_data']['email'],
//         'phone' => $data['customer_data']['phone'],
//         'first_name' => $data['customer_data']['first_name'],
//         'last_name' => $data['customer_data']['last_name']
//     ]
// ]);

// EXAMPLE 3: Update CRM - Payment Received
// -------------------------------------------------------------------
// $crm->updateOpportunity([
//     'stage' => 'paid',
//     'invoice_id' => $data['invoice_id'],
//     'transaction_id' => $data['transaction_id'],
//     'payment_method' => $data['payment_method'],
//     'amount_received' => $data['amount_paid'],
//     'paid_at' => $data['timestamp']
// ]);

// EXAMPLE 4: Notify Accounting System
// -------------------------------------------------------------------
// $accounting->recordRevenue([
//     'invoice_id' => $data['invoice_id'],
//     'transaction_id' => $data['transaction_id'],
//     'amount' => $data['amount_paid'],
//     'currency' => $data['currency'],
//     'payment_gateway' => $data['payment_gateway'],
//     'gateway_fee' => calculateGatewayFee($data['payment_gateway'], $data['amount_paid']),
//     'net_amount' => $data['amount_paid'] - calculateGatewayFee($data['payment_gateway'], $data['amount_paid']),
//     'category' => 'Hotel Booking',
//     'customer_email' => $data['customer_data']['email'],
//     'booking_date' => $data['booking_details']['created_at'],
//     'payment_date' => $data['timestamp']
// ]);

// EXAMPLE 5: Notify Internal Teams
// -------------------------------------------------------------------
// sendSlackNotification('#payments', [
//     'text' => '💰 Payment Received!',
//     'fields' => [
//         'Invoice' => $data['invoice_id'],
//         'Transaction' => $data['transaction_id'],
//         'Amount' => "{$data['currency']} {$data['amount_paid']}",
//         'Method' => ucfirst($data['payment_method']),
//         'Gateway' => ucfirst($data['payment_gateway']),
//         'Hotel' => $data['hotel_data']['name'],
//         'Customer' => $data['customer_data']['email']
//     ],
//     'actions' => [
//         ['text' => 'View Invoice', 'url' => $data['invoice_url']]
//     ]
// ]);

// EXAMPLE 6: Send Payment Confirmation Email
// -------------------------------------------------------------------
// sendEmail($data['customer_data']['email'], 'payment_confirmation', [
//     'invoice_id' => $data['invoice_id'],
//     'transaction_id' => $data['transaction_id'],
//     'amount_paid' => $data['amount_paid'],
//     'currency' => $data['currency'],
//     'hotel_name' => $data['hotel_data']['name'],
//     'payment_method' => $data['payment_method'],
//     'invoice_link' => $data['invoice_url']
// ]);

// EXAMPLE 7: Update Customer Lifetime Value
// -------------------------------------------------------------------
// $customerProfile->update($data['user_id'], [
//     'lifetime_value[+]' => $data['amount_paid'],
//     'total_payments[+]' => 1,
//     'last_payment_date' => $data['timestamp'],
//     'last_payment_amount' => $data['amount_paid'],
//     'preferred_payment_method' => $data['payment_method']
// ]);

// EXAMPLE 8: Trigger Post-Payment Automation
// -------------------------------------------------------------------
// // Add to loyalty program if high value
// if ($data['amount_paid'] > 500) {
//     $loyaltyProgram->addPoints($data['user_id'], calculatePoints($data['amount_paid']));
// }
// 
// // Send hotel vouchers/info
// scheduleEmail('travel_documents', $data['customer_data']['email'], [
//     'hotel_name' => $data['hotel_data']['name'],
//     'checkin_date' => $data['booking_details']['checkin'],
//     'booking_reference' => $data['invoice_id']
// ], '-7 days', $data['booking_details']['checkin']);

/**
 * WEBHOOK: stays.payment.failed
 * Triggers when payment fails
 * 
 * @param array $data Payment failure information
 * @return void
 * 
 * PAYLOAD STRUCTURE:
 * - invoice_id: Invoice ID
 * - booking_id: Booking ID
 * - user_id: Customer user ID
 * - payment_gateway: Gateway that failed
 * - payment_method: Payment method attempted
 * - amount: Amount attempted
 * - currency: Currency code
 * - error_message: Failure reason
 * - error_code: Gateway error code
 * - customer_email: Customer email
 * - hotel_name: Hotel name
 * - timestamp: Failure timestamp
 * - retry_count: Number of attempts (if tracked)
 * 
 * INTEGRATION EXAMPLES:
 */
// triggerWebhook('stays/payment', 'stays.payment.failed', $data);

// EXAMPLE 1: Track Payment Failures
// -------------------------------------------------------------------
// trackEvent('payment_failed', [
//     'invoice_id' => $data['invoice_id'],
//     'gateway' => $data['payment_gateway'],
//     'method' => $data['payment_method'],
//     'error_code' => $data['error_code'],
//     'error_message' => $data['error_message'],
//     'value' => $data['amount']
// ]);

// EXAMPLE 2: Alert Team of Payment Issues
// -------------------------------------------------------------------
// sendSlackNotification('#payment-issues', [
//     'text' => '❌ Payment Failed',
//     'fields' => [
//         'Invoice' => $data['invoice_id'],
//         'Amount' => "{$data['currency']} {$data['amount']}",
//         'Gateway' => $data['payment_gateway'],
//         'Error' => $data['error_message'],
//         'Customer' => $data['customer_email'],
//         'Retry Count' => $data['retry_count'] ?? 1
//     ]
// ]);

// EXAMPLE 3: Send Customer Support Email
// -------------------------------------------------------------------
// sendEmail($data['customer_email'], 'payment_failed_support', [
//     'invoice_id' => $data['invoice_id'],
//     'error_message' => $data['error_message'],
//     'hotel_name' => $data['hotel_name'],
//     'support_link' => 'https://yoursite.com/support',
//     'retry_link' => "https://yoursite.com/invoice/stays/{$data['invoice_id']}"
// ]);

// EXAMPLE 4: Flag for Manual Review if Multiple Failures
// -------------------------------------------------------------------
// if ($data['retry_count'] >= 3) {
//     $fraudDetection->flagForReview([
//         'invoice_id' => $data['invoice_id'],
//         'customer_email' => $data['customer_email'],
//         'reason' => 'Multiple payment failures',
//         'failure_count' => $data['retry_count'],
//         'error_codes' => $data['error_history'] ?? []
//     ]);
// }

// EXAMPLE 5: Update CRM
// -------------------------------------------------------------------
// $crm->logActivity([
//     'type' => 'payment_failed',
//     'invoice_id' => $data['invoice_id'],
//     'error' => $data['error_message'],
//     'gateway' => $data['payment_gateway'],
//     'timestamp' => $data['timestamp']
// ]);

/**
 * WEBHOOK: stays.payment.cancelled
 * Triggers when user cancels payment
 * 
 * @param array $data Payment cancellation information
 * @return void
 * 
 * PAYLOAD STRUCTURE:
 * - invoice_id: Invoice ID
 * - booking_id: Booking ID
 * - user_id: Customer user ID
 * - payment_gateway: Gateway where cancelled
 * - amount: Amount not paid
 * - currency: Currency code
 * - customer_email: Customer email
 * - hotel_name: Hotel name
 * - cancellation_stage: Where in payment flow cancelled
 * - timestamp: Cancellation timestamp
 * 
 * INTEGRATION EXAMPLES:
 */
// triggerWebhook('stays/payment', 'stays.payment.cancelled', $data);

// EXAMPLE 1: Track Cancellation Patterns
// -------------------------------------------------------------------
// trackEvent('payment_cancelled', [
//     'invoice_id' => $data['invoice_id'],
//     'gateway' => $data['payment_gateway'],
//     'cancellation_stage' => $data['cancellation_stage'],
//     'value' => $data['amount']
// ]);

// EXAMPLE 2: Send Win-Back Email
// -------------------------------------------------------------------
// scheduleEmail('payment_cancelled_followup', $data['customer_email'], [
//     'hotel_name' => $data['hotel_name'],
//     'invoice_link' => "https://yoursite.com/invoice/stays/{$data['invoice_id']}",
//     'support_link' => 'https://yoursite.com/support'
// ], '+1 hour');

// EXAMPLE 3: Log in CRM
// -------------------------------------------------------------------
// $crm->updateOpportunity([
//     'stage' => 'payment_abandoned',
//     'lost_reason' => "Payment cancelled at {$data['cancellation_stage']}",
//     'invoice_id' => $data['invoice_id'],
//     'timestamp' => $data['timestamp']
// ]);

/**
 * WEBHOOK: stays.payment.refund_requested
 * Triggers when customer requests a refund
 * 
 * @param array $data Refund request information
 * @return void
 * 
 * PAYLOAD STRUCTURE:
 * - invoice_id: Invoice ID
 * - booking_id: Booking ID
 * - transaction_id: Original transaction ID
 * - refund_amount: Amount to refund
 * - refund_reason: Reason for refund
 * - customer_email: Customer email
 * - hotel_name: Hotel name
 * - requested_by: Who requested (user_id or admin_id)
 * - timestamp: Request timestamp
 * 
 * INTEGRATION EXAMPLES:
 */
// triggerWebhook('stays/payment', 'stays.payment.refund_requested', $data);

// EXAMPLE 1: Notify Customer Service Team
// -------------------------------------------------------------------
// sendSlackNotification('#refund-requests', [
//     'text' => '🔄 Refund Requested',
//     'fields' => [
//         'Invoice' => $data['invoice_id'],
//         'Amount' => "{$data['currency']} {$data['refund_amount']}",
//         'Reason' => $data['refund_reason'],
//         'Customer' => $data['customer_email'],
//         'Hotel' => $data['hotel_name']
//     ],
//     'actions' => [
//         ['text' => 'Process Refund', 'url' => "https://yoursite.com/admin/refunds/{$data['invoice_id']}"]
//     ]
// ]);

// EXAMPLE 2: Track Refund Requests
// -------------------------------------------------------------------
// trackEvent('refund_requested', [
//     'invoice_id' => $data['invoice_id'],
//     'reason' => $data['refund_reason'],
//     'value' => $data['refund_amount']
// ]);

// EXAMPLE 3: Update CRM
// -------------------------------------------------------------------
// $crm->createTask([
//     'type' => 'refund_request',
//     'invoice_id' => $data['invoice_id'],
//     'customer_email' => $data['customer_email'],
//     'amount' => $data['refund_amount'],
//     'reason' => $data['refund_reason'],
//     'status' => 'pending',
//     'assigned_to' => 'customer_service_team'
// ]);

/**
 * WEBHOOK: stays.payment.refunded
 * Triggers when refund is successfully processed
 * 
 * @param array $data Refund completion information
 * @return void
 * 
 * PAYLOAD STRUCTURE:
 * - invoice_id: Invoice ID
 * - booking_id: Booking ID
 * - transaction_id: Original transaction ID
 * - refund_transaction_id: Refund transaction ID
 * - refund_amount: Amount refunded
 * - currency: Currency code
 * - refund_reason: Reason for refund
 * - customer_email: Customer email
 * - hotel_name: Hotel name
 * - processed_by: Admin who processed (if applicable)
 * - timestamp: Refund completion timestamp
 * 
 * INTEGRATION EXAMPLES:
 */
// triggerWebhook('stays/payment', 'stays.payment.refunded', $data);

// EXAMPLE 1: Send Refund Confirmation Email
// -------------------------------------------------------------------
// sendEmail($data['customer_email'], 'refund_processed', [
//     'invoice_id' => $data['invoice_id'],
//     'refund_amount' => $data['refund_amount'],
//     'currency' => $data['currency'],
//     'refund_transaction_id' => $data['refund_transaction_id'],
//     'hotel_name' => $data['hotel_name'],
//     'processing_time' => '3-5 business days'
// ]);

// EXAMPLE 2: Update Accounting
// -------------------------------------------------------------------
// $accounting->recordRefund([
//     'invoice_id' => $data['invoice_id'],
//     'original_transaction_id' => $data['transaction_id'],
//     'refund_transaction_id' => $data['refund_transaction_id'],
//     'amount' => $data['refund_amount'],
//     'currency' => $data['currency'],
//     'reason' => $data['refund_reason'],
//     'processed_at' => $data['timestamp']
// ]);

// EXAMPLE 3: Track Refund Completion
// -------------------------------------------------------------------
// trackEvent('refund_completed', [
//     'invoice_id' => $data['invoice_id'],
//     'refund_transaction_id' => $data['refund_transaction_id'],
//     'value' => $data['refund_amount'],
//     'reason' => $data['refund_reason']
// ]);

// EXAMPLE 4: Notify Team
// -------------------------------------------------------------------
// sendSlackNotification('#refunds', [
//     'text' => '✅ Refund Processed',
//     'fields' => [
//         'Invoice' => $data['invoice_id'],
//         'Amount' => "{$data['currency']} {$data['refund_amount']}",
//         'Refund ID' => $data['refund_transaction_id'],
//         'Customer' => $data['customer_email'],
//         'Processed By' => $data['processed_by'] ?? 'System'
//     ]
// ]);

// EXAMPLE 5: Update CRM
// -------------------------------------------------------------------
// $crm->updateOpportunity([
//     'stage' => 'refunded',
//     'refund_amount' => $data['refund_amount'],
//     'refund_reason' => $data['refund_reason'],
//     'refunded_at' => $data['timestamp']
// ]);
// 
// // Update customer lifetime value
// $customerProfile->update($data['user_id'], [
//     'lifetime_value[-]' => $data['refund_amount'],
//     'total_refunds[+]' => 1,
//     'last_refund_date' => $data['timestamp']
// ]);

// =============================================================================
// SEND PAYMENT NOTIFICATIONS
// =============================================================================
if ($event === 'stays.payment.completed') {
    try {
    // Get booking details from database
    global $db;
    $booking = $db->get('bookings', '*', ['invoice_id' => $data['invoice_id']]);
    
    if ($booking) {
        // Decode booking data for hotel details
        $bookingDetails = json_decode($booking['booking_data'] ?? $booking['data'] ?? '{}', true);
        $hotelId = $bookingDetails['hotel_id'] ?? $bookingDetails['id'] ?? 0;
        
        // FETCH SERVICE OWNER (VENDOR) ID FROM STAYS TABLE
        $serviceOwnerId = null;
        if (!empty($hotelId)) {
            $hotel = $db->get('stays', ['user_id'], ['id' => $hotelId]);
            if ($hotel && !empty($hotel['user_id'])) {
                $serviceOwnerId = $hotel['user_id'];
            }
        }

        // Generate PDF
        $pdfPath = GENERATE_BOOKING_PDF($booking['invoice_id']);

        // Prepare notification data with hotel details
        $notificationData = [
            'invoice_id' => $data['invoice_id'],
            'amount' => $data['amount_paid'] ?? $booking['price_markup'],
            'currency' => $booking['currency_markup'] ?? 'USD',
            'payment_status' => 'paid',
            'transaction_id' => $data['transaction_id'] ?? '',
            'module_type' => 'Stay',
            'user_id' => $serviceOwnerId, // Explicitly passed vendor ID
            
            // Hotel specific data for template
            'hotel_name' => $bookingDetails['hotel_name'] ?? '',
            'hotelName' => $bookingDetails['hotel_name'] ?? '',
            'hotel_address' => $bookingDetails['hotel_address'] ?? '',
            'hotelAddress' => $bookingDetails['hotel_address'] ?? '',
            'checkin' => $bookingDetails['checkin'] ?? '',
            'checkout' => $bookingDetails['checkout'] ?? '',
            'nights' => $bookingDetails['nights'] ?? 0,
            'room_type' => $bookingDetails['room_type'] ?? 'Standard Room',
            'roomType' => $bookingDetails['room_type'] ?? 'Standard Room',
            'discount' => 0 
        ];

        // Send payment confirmation notification (Automated to Customer, Owner, and Admins)
        NOTIFY::payment('stays', [
            'email' => $booking['email'] ?? '',
            'phone' => $booking['phone'] ?? '',
            'first_name' => $booking['first_name'] ?? '',
            'last_name' => $booking['last_name'] ?? '',
            'country_code' => $booking['phone_country_code'] ?? ''
        ], $notificationData, $pdfPath);
    }
    } catch (\Throwable $e) {
        error_log("STAYS_PAYMENT_WEBHOOK ERROR: " . $e->getMessage());
    }
}

// =============================================================================
// WEBHOOK RESPONSE - Always return status array
// =============================================================================
return [
    'status' => 'success',
    'message' => "Webhook processed: {$event}"
];
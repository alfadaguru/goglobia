<?php
/**
 * =============================================================================
 * CARS PAYMENT WEBHOOKS
 * =============================================================================
 * 
 * These webhooks fire during the payment process. Use them to track payment
 * flows, update accounting systems, trigger refunds, and monitor transactions.
 * 
 * AVAILABLE EVENTS:
 * 1. cars.payment.initiated - When payment process starts
 * 2. cars.payment.method_selected - When user selects payment method
 * 3. cars.payment.processing - When payment is being processed
 * 4. cars.payment.completed - When payment is successful
 * 5. cars.payment.failed - When payment fails
 * 6. cars.payment.cancelled - When payment is cancelled by user
 * 7. cars.payment.refund_requested - When refund is requested
 * 8. cars.payment.refunded - When refund is processed
 * 
 * =============================================================================
 */

// Handle the webhook event
// Variables available: $event (event name), $data (event data)

// Send payment notifications when payment is completed
if ($event === 'cars.payment.completed') {
    try {
    // Get booking details from database
    global $db;
    $booking = $db->get('bookings', '*', ['invoice_id' => $data['invoice_id']]);
    
    if ($booking) {
        // Send payment confirmation notification
        NOTIFY::payment('cars', [
            'email' => $booking['email'] ?? '',
            'phone' => $booking['phone'] ?? '',
            'first_name' => $booking['first_name'] ?? '',
            'last_name' => $booking['last_name'] ?? '',
            'country_code' => $booking['phone_country_code'] ?? ''
        ], [
            'invoice_id' => $data['invoice_id'],
            'amount' => $data['amount_paid'] ?? $booking['price_markup'],
            'currency' => $booking['currency_markup'] ?? 'USD',
            'payment_status' => 'paid',
            'transaction_id' => $data['transaction_id'] ?? '',
            'module_type' => 'Car Rental'
        ]);
    }
    } catch (\Throwable $e) {
        error_log("CARS_PAYMENT_WEBHOOK ERROR: " . $e->getMessage());
    }
}

// Example: Log critical payment events
if (in_array($event, ['cars.payment.completed', 'cars.payment.failed', 'cars.payment.refunded'])) {
}

// Always return a response array
return [
    'status' => 'success',
    'message' => "Webhook processed: {$event}"
];
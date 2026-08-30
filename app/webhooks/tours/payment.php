<?php
/**
 * =============================================================================
 * TOURS PAYMENT WEBHOOKS
 * =============================================================================
 * 
 * These webhooks fire during the payment process. Use them to track payment
 * flows, update accounting systems, trigger refunds, and monitor transactions.
 * 
 * AVAILABLE EVENTS:
 * 1. tours.payment.initiated - When payment process starts
 * 2. tours.payment.method_selected - When user selects payment method
 * 3. tours.payment.processing - When payment is being processed
 * 4. tours.payment.completed - When payment is successful
 * 5. tours.payment.failed - When payment fails
 * 6. tours.payment.cancelled - When payment is cancelled by user
 * 7. tours.payment.refund_requested - When refund is requested
 * 8. tours.payment.refunded - When refund is processed
 * 
 * =============================================================================
 */

// Handle the webhook event
// Variables available: $event (event name), $data (event data)

// Send payment notifications when payment is completed
if ($event === 'tours.payment.completed') {
    try {
    // Get booking details from database
    global $db;
    $booking = $db->get('bookings', '*', ['invoice_id' => $data['invoice_id']]);
    
    if ($booking) {
        // Decode booking data for tour details
        $bookingData = json_decode($booking['booking_data'] ?? $booking['data'] ?? '{}', true);
        $tourId = $bookingData['tour_id'] ?? $bookingData['id'] ?? 0;

        // FETCH SERVICE OWNER (VENDOR) ID FROM TOURS TABLE
        $serviceOwnerId = null;
        if (!empty($tourId)) {
            $tour = $db->get('tours', ['user_id'], ['id' => $tourId]);
            if ($tour && !empty($tour['user_id'])) {
                $serviceOwnerId = $tour['user_id'];
            }
        }

        // Generate PDF
        $pdfPath = GENERATE_BOOKING_PDF($booking['invoice_id']);

        // Prepare notification data with tour details for template
        $notificationData = [
            'invoice_id' => $data['invoice_id'],
            'amount' => $data['amount_paid'] ?? $booking['price_markup'],
            'currency' => $booking['currency_markup'] ?? 'USD',
            'payment_status' => 'paid',
            'transaction_id' => $data['transaction_id'] ?? '',
            'module_type' => 'Tour',
            'user_id' => $serviceOwnerId, // Explicitly passed vendor ID
            
            // Tour specific data
            'tour_name' => $bookingData['tour_name'] ?? '',
            'tourName' => $bookingData['tour_name'] ?? '',
            'tour_location' => $bookingData['location'] ?? '',
            'tourLocation' => $bookingData['location'] ?? '',
            'start_date' => $bookingData['start_date'] ?? '',
            'startDate' => $bookingData['start_date'] ?? '',
            'duration' => $bookingData['duration'] ?? '',
            'adults' => $booking['adults'],
            'children' => $booking['childs'],
            'infants' => (string)$booking['infants'],
            'special_requests' => $booking['special_requests'] ?? ''
        ];

        // Send payment confirmation notification (Automated to Customer, Owner, and Admins)
        NOTIFY::payment('tours', [
            'email' => $booking['email'] ?? '',
            'phone' => $booking['phone'] ?? '',
            'first_name' => $booking['first_name'] ?? '',
            'last_name' => $booking['last_name'] ?? '',
            'country_code' => $booking['phone_country_code'] ?? ''
        ], $notificationData, $pdfPath);
    }
    } catch (\Throwable $e) {
        error_log("TOURS_PAYMENT_WEBHOOK ERROR: " . $e->getMessage());
    }
}

// Example: Log critical payment events
if (in_array($event, ['tours.payment.completed', 'tours.payment.failed', 'tours.payment.refunded'])) {
}

// Always return a response array
return [
    'status' => 'success',
    'message' => "Webhook processed: {$event}"
];
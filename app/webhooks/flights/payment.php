<?php
/**
 * =============================================================================
 * FLIGHTS PAYMENT WEBHOOKS
 * =============================================================================
 * 
 * These webhooks fire during the payment process. Use them to track payment
 * flows, update accounting systems, trigger refunds, and monitor transactions.
 * 
 * AVAILABLE EVENTS:
 * 1. flights.payment.initiated - When payment process starts
 * 2. flights.payment.method_selected - When user selects payment method
 * 3. flights.payment.processing - When payment is being processed
 * 4. flights.payment.completed - When payment is successful
 * 5. flights.payment.failed - When payment fails
 * 6. flights.payment.cancelled - When payment is cancelled by user
 * 7. flights.payment.refund_requested - When refund is requested
 * 8. flights.payment.refunded - When refund is processed
 * 
 * =============================================================================
 */

// Handle the webhook event
// Variables available: $event (event name), $data (event data)

// Send payment notifications when payment is completed
if ($event === 'flights.payment.completed') {
    try {
    // Get booking details from database
    global $db;
    $booking = $db->get('bookings', '*', ['invoice_id' => $data['invoice_id']]);
    
    if ($booking) {
        // Decode booking data for flight details
        $bookingData = json_decode($booking['booking_data'] ?? $booking['data'] ?? '{}', true);
        $flightData = $bookingData['flight_data'] ?? [];
        
        // FETCH SERVICE OWNER (VENDOR) ID FROM FLIGHTS TABLE (if manual flight)
        $serviceOwnerId = null;
        // Check nested booking_data first (primary location for manual flights)
        $flightId = $flightData['booking_data']['flight_id'] ?? $flightData['id'] ?? $flightData['flight_id'] ?? 0;
        
        if ($flightId > 0) {
            $flight = $db->get('flights', ['user_id'], ['id' => $flightId]);
            if ($flight && !empty($flight['user_id'])) {
                $serviceOwnerId = $flight['user_id'];
            }
        }

        // Generate PDF
        $pdfPath = GENERATE_BOOKING_PDF($booking['invoice_id']);

        // Prepare notification data with flight details for template
        $notificationData = [
            'invoice_id' => $data['invoice_id'],
            'amount' => $data['amount_paid'] ?? $booking['price_markup'],
            'currency' => $booking['currency_markup'] ?? 'USD',
            'payment_status' => 'paid',
            'transaction_id' => $data['transaction_id'] ?? '',
            'module_type' => 'Flight',
            'user_id' => $serviceOwnerId, // Explicitly passed vendor ID
            
            // Flight specific data
            'from' => $flightData['from'] ?? $bookingData['from'] ?? '',
            'to' => $flightData['to'] ?? $bookingData['to'] ?? '',
            'departure_date' => $flightData['departure_date'] ?? $bookingData['departure_date'] ?? '',
            'date' => $flightData['departure_date'] ?? $bookingData['departure_date'] ?? '',
            'flight_number' => $flightData['flight_number'] ?? ($flightData['routes'][0]['flight_number'] ?? ''),
            'airline_name' => $flightData['airline_name'] ?? ($flightData['routes'][0]['airline_name'] ?? ''),
            'booking_data' => $booking['booking_data']
        ];

        // Send payment confirmation notification (Automated to Customer, Owner, and Admins)
        NOTIFY::payment('flights', [
            'email' => $booking['email'] ?? '',
            'phone' => $booking['phone'] ?? '',
            'first_name' => $booking['first_name'] ?? '',
            'last_name' => $booking['last_name'] ?? '',
            'country_code' => $booking['phone_country_code'] ?? ''
        ], $notificationData, $pdfPath);
    }
    } catch (\Throwable $e) {
        error_log("FLIGHTS_PAYMENT_WEBHOOK ERROR: " . $e->getMessage());
    }
}

// Example: Log critical payment events
if (in_array($event, ['flights.payment.completed', 'flights.payment.failed', 'flights.payment.refunded'])) {
}

// Always return a response array
return [
    'status' => 'success',
    'message' => "Webhook processed: {$event}"
];
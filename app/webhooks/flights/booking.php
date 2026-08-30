<?php
/**
 * =============================================================================
 * FLIGHTS BOOKING WEBHOOKS
 * =============================================================================
 * 
 * These webhooks fire during the flight booking process. Use them to track
 * bookings, trigger CRM updates, send notifications, and sync with external
 * systems.
 * 
 * AVAILABLE EVENTS:
 * 1. flights.booking.flight_viewed - When user views flight details/pricing
 * 2. flights.booking.draft_created - When booking draft is saved
 * 3. flights.booking.initiated - When user starts booking process
 * 4. flights.booking.user_created - When guest account is auto-created
 * 5. flights.booking.confirmed - When booking is successfully confirmed
 * 6. flights.booking.failed - When booking fails
 * 7. flights.booking.email_sent - When confirmation email is sent
 * 
 * =============================================================================
 */

// Handle the webhook event
// Variables available: $event (event name), $data (event data)

// Your custom webhook logic here:
// - Send to external API
// - Update CRM
// - Send notifications
// - Log to external systems
// - etc.

// Example: Log critical events
if (in_array($event, ['flights.booking.confirmed', 'flights.booking.failed'])) {
}

// Send booking notifications when booking is confirmed
$notifyResult = null;
if ($event === 'flights.booking.confirmed') {
    try {
    global $db;
    
    // Get full booking data from database
    $booking = $db->get('bookings', '*', ['invoice_id' => $data['invoice_id']]);
    
    if ($booking) {
        // Decode flight data
        $bookingData = json_decode($booking['booking_data'], true);
        $flightData = $bookingData['flight_data'] ?? [];
        
        // FETCH SERVICE OWNER (VENDOR) ID FROM FLIGHTS TABLE
        $serviceOwnerId = null;
        // Check nested booking_data first (primary location for manual flights)
        $flightId = $flightData['booking_data']['flight_id'] ?? $flightData['id'] ?? $flightData['flight_id'] ?? 0;
        
        if (!empty($flightId)) {
            $flight = $db->get('flights', ['user_id'], ['id' => $flightId]);
            if ($flight && !empty($flight['user_id'])) {
                $serviceOwnerId = $flight['user_id'];
            }
        }
        
        // Prepare customer data
        $customerData = [
            'first_name' => $booking['first_name'],
            'last_name' => $booking['last_name'],
            'email' => $booking['email'],
            'phone' => $booking['phone'],
            'phone_country_code' => $booking['phone_country_code']
        ];
        
        // Prepare notification data with flight details
        $notificationData = [
            'invoice_id' => $booking['invoice_id'],
            'amount' => $booking['price_markup'],
            'currency' => $booking['currency_markup'],
            'payment_status' => $booking['payment_status'],
            'payment_method' => $booking['payment_gateway'],
            'booking_date' => $booking['created_at'],
            'adults' => $booking['adults'],
            'children' => $booking['childs'],
            'infants' => $booking['infants'],
            'module_type' => 'flights',
            'user_id' => $serviceOwnerId, // Explicitly passed vendor ID
            // Flight specific data
            'from' => $flightData['from'] ?? ($flightData['departure_code'] ?? ''),
            'to' => $flightData['to'] ?? ($flightData['arrival_code'] ?? ''),
            'departure_date' => $flightData['departure_date'] ?? '',
            'airline_name' => $flightData['airline'] ?? '',
            'airlineName' => $flightData['airline'] ?? '', // Ensure camelCase exists
            'flight_number' => $flightData['flight_no'] ?? '',
            'flightNumber' => $flightData['flight_no'] ?? '', // Ensure camelCase exists
            'flight_data' => $flightData,
            'discount' => 0 // Default discount to 0
        ];
        
        // Generate PDF if not already generated
        $pdfPath = GENERATE_BOOKING_PDF($booking['invoice_id']);
        
        // Send notification
        $notifyResult = NOTIFY::booking('flights', $customerData, $notificationData, $pdfPath);
    }
    } catch (\Throwable $e) {
        error_log("FLIGHTS_BOOKING_WEBHOOK ERROR: " . $e->getMessage());
    }
}

// Reflect the actual notification outcome in the webhook log — a booking that
// confirmed but whose customer email failed to send must NOT be logged as a
// plain 'success', or the failure becomes invisible outside the PHP error log.
$emailResult = $notifyResult['email'] ?? null;
if ($emailResult && $emailResult['attempted'] && !$emailResult['customer_sent']) {
    return [
        'status' => 'error',
        'message' => "Webhook processed but customer notification email failed to send (invoice {$data['invoice_id']})"
    ];
}

return [
    'status' => 'success',
    'message' => "Webhook processed: {$event}"
];
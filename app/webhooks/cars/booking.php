<?php
/**
 * =============================================================================
 * CARS BOOKING WEBHOOKS
 * =============================================================================
 * 
 * These webhooks fire during the car rental booking process. Use them to track
 * bookings, trigger CRM updates, send notifications, and sync with external
 * systems.
 * 
 * AVAILABLE EVENTS:
 * 1. cars.booking.car_viewed - When user views car details page
 * 2. cars.booking.draft_created - When booking draft is saved
 * 3. cars.booking.initiated - When user starts booking process
 * 4. cars.booking.user_created - When guest account is auto-created
 * 5. cars.booking.confirmed - When booking is successfully confirmed
 * 6. cars.booking.failed - When booking fails
 * 7. cars.booking.email_sent - When confirmation email is sent
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

// Send booking notifications when booking is confirmed
if ($event === 'cars.booking.confirmed') {
    try {
    global $db;
    
    // Get full booking data from database
    $booking = $db->get('bookings', '*', ['invoice_id' => $data['invoice_id']]);
    
    if ($booking) {
        // Decode car data
        $bookingData = json_decode($booking['booking_data'], true);
        $carData = $bookingData['car_data'] ?? [];
        
        
        // FETCH SERVICE OWNER (VENDOR) ID
        $carOwnerId = null;
        if (!empty($bookingData['user_id'])) {
            $carOwnerId = $bookingData['user_id'];
        } else {
            $carId = $carData['id'] ?? $carData['supplier_id'] ?? 0;
            if (!empty($carId)) {
                $car = $db->get('cars', ['user_id'], ['id' => $carId]);
                if ($car && !empty($car['user_id'])) {
                    $carOwnerId = $car['user_id'];
                } else {
                    error_log("CAR_BOOKING_WEBHOOK: Could not find owner for car ID: " . $carId);
                }
            } else {
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
        
        // Prepare notification data
        $notificationData = array_merge($carData, [
            'invoice_id' => $booking['invoice_id'],
            'amount' => $booking['price_markup'],
            'final_total' => $booking['price_markup'],
            'currency' => $booking['currency_markup'],
            'payment_status' => $booking['payment_status'],
            'payment_method' => $booking['payment_gateway'],
            'booking_date' => $booking['created_at'],
            'module_type' => 'cars',
            'user_id' => $carOwnerId, // Explicitly passed vendor ID
            'search_params' => $bookingData['search_params'] ?? []
        ]);
        
        // Generate PDF
        $pdfPath = null;
        try {
            $pdfPath = GENERATE_BOOKING_PDF($booking['invoice_id']);
        } catch (Exception $e) {
            error_log("CAR_BOOKING_WEBHOOK ERROR (PDF): " . $e->getMessage());
        }
        
        // Send notification
        try {
            NOTIFY::booking('cars', $customerData, $notificationData, $pdfPath);
        } catch (Exception $e) {
            error_log("CAR_BOOKING_WEBHOOK ERROR (NOTIFY): " . $e->getMessage());
        }
    } else {
        error_log("CAR_BOOKING_WEBHOOK ERROR: Booking not found for invoice_id: " . $data['invoice_id']);
    }
    } catch (\Throwable $e) {
        error_log("CAR_BOOKING_WEBHOOK ERROR: " . $e->getMessage());
    }
}

// Always return a response array
return [
    'status' => 'success',
    'message' => "Webhook processed: {$event}"
];
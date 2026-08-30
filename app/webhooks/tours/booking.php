<?php
/**
 * =============================================================================
 * TOURS BOOKING WEBHOOKS
 * =============================================================================
 * 
 * These webhooks fire during the tour booking process. Use them to track
 * bookings, trigger CRM updates, send notifications, and sync with external
 * systems.
 * 
 * AVAILABLE EVENTS:
 * 1. tours.booking.tour_viewed - When user views tour details page
 * 2. tours.booking.draft_created - When booking draft is saved
 * 3. tours.booking.initiated - When user starts booking process
 * 4. tours.booking.user_created - When guest account is auto-created
 * 5. tours.booking.confirmed - When booking is successfully confirmed
 * 6. tours.booking.failed - When booking fails
 * 7. tours.booking.email_sent - When confirmation email is sent
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
if (in_array($event, ['tours.booking.confirmed', 'tours.booking.failed'])) {
}

// Send booking notifications when booking is confirmed
$notifyResult = null;
if ($event === 'tours.booking.confirmed') {
    try {
    global $db;
    
    // Get full booking data from database
    $booking = $db->get('bookings', '*', ['invoice_id' => $data['invoice_id']]);
    
    if ($booking) {
        // Decode tour data
        $bookingData = json_decode($booking['booking_data'], true);
        
        // FETCH SERVICE OWNER (VENDOR) ID FROM TOURS TABLE
        $serviceOwnerId = null;
        if (!empty($bookingData['tour_id'])) {
            $tour = $db->get('tours', ['user_id'], ['id' => $bookingData['tour_id']]);
            if ($tour && !empty($tour['user_id'])) {
                $serviceOwnerId = $tour['user_id'];
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
        
        // Prepare notification data with tour details
        $notificationData = [
            'invoice_id' => $booking['invoice_id'],
            'amount' => $booking['price_markup'],
            'currency' => $booking['currency_markup'],
            'payment_status' => $booking['payment_status'],
            'payment_method' => $booking['payment_gateway'],
            'booking_date' => $booking['created_at'],
            'adults' => $booking['adults'],
            'children' => $booking['childs'],
            'module_type' => 'tours',
            'user_id' => $serviceOwnerId, // Explicitly passed vendor ID
            // Tour specific data
            'tour_name' => $bookingData['tour_name'] ?? '',
            'tourName' => $bookingData['tour_name'] ?? '', // Ensure both cases exist
            'tour_location' => $bookingData['location'] ?? '',
            'tourLocation' => $bookingData['location'] ?? '', // Ensure both cases exist
            'start_date' => $bookingData['date'] ?? '',
            'startDate' => $bookingData['date'] ?? '',
            'duration' => ($bookingData['days'] ?? '1') . ' Days / ' . ($bookingData['nights'] ?? '0') . ' Nights',
            'discount' => 0 // Default discount to 0 to prevent template error
        ];
        
        // Generate PDF if not already generated
        $pdfPath = GENERATE_BOOKING_PDF($booking['invoice_id']);
        
        // Send notification
        $notifyResult = NOTIFY::booking('tours', $customerData, $notificationData, $pdfPath);
    }
    } catch (\Throwable $e) {
        error_log("TOURS_BOOKING_WEBHOOK ERROR: " . $e->getMessage());
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
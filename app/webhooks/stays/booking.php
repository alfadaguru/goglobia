<?php
/**
 * =============================================================================
 * STAYS BOOKING WEBHOOKS
 * =============================================================================
 * 
 * These webhooks fire during the hotel booking process. Use them to track
 * bookings, trigger CRM updates, send notifications, and sync with external
 * systems.
 * 
 * AVAILABLE EVENTS:
 * 1. stays.booking.hotel_viewed - When user views hotel details page
 * 2. stays.booking.draft_created - When booking draft is saved
 * 3. stays.booking.initiated - When user starts booking process
 * 4. stays.booking.user_created - When guest account is auto-created
 * 5. stays.booking.confirmed - When booking is successfully confirmed
 * 6. stays.booking.failed - When booking fails
 * 7. stays.booking.email_sent - When confirmation email is sent
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
if (in_array($event, ['stays.booking.confirmed', 'stays.booking.failed'])) {
}

// Send booking notifications when booking is confirmed
$notifyResult = null;
if ($event === 'stays.booking.confirmed') {
    try {
    global $db;
    
    // Get full booking data from database
    $booking = $db->get('bookings', '*', ['invoice_id' => $data['invoice_id']]);
    
    if ($booking) {
        // Decode hotel data
        $bookingData = json_decode($booking['booking_data'], true);
        
        // FETCH SERVICE OWNER (VENDOR) ID FROM STAYS TABLE
        $serviceOwnerId = null;
        $hotelId = $bookingData['hotel_id'] ?? $bookingData['id'] ?? 0;
        
        if (!empty($hotelId)) {
            // FIX: Changed table from 'hotels' to 'stays'
            $hotel = $db->get('stays', ['user_id'], ['id' => $hotelId]);
            if ($hotel && !empty($hotel['user_id'])) {
                $serviceOwnerId = $hotel['user_id'];
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
        
        // Prepare notification data with hotel details
        $notificationData = [
            'invoice_id' => $booking['invoice_id'],
            'amount' => $booking['price_markup'],
            'currency' => $booking['currency_markup'],
            'payment_status' => $booking['payment_status'],
            'payment_method' => $booking['payment_gateway'],
            'booking_date' => $booking['created_at'],
            'adults' => $booking['adults'],
            'children' => $booking['childs'],
            'module_type' => 'stays',
            'user_id' => $serviceOwnerId, // Explicitly passed vendor ID
            // Hotel specific data
            'hotel_name' => $bookingData['hotel_name'] ?? '',
            'hotelName' => $bookingData['hotel_name'] ?? '',
            'hotel_address' => $bookingData['hotel_address'] ?? '',
            'hotelAddress' => $bookingData['hotel_address'] ?? '',
            'checkin' => $bookingData['checkin'] ?? '',
            'checkout' => $bookingData['checkout'] ?? '',
            'nights' => $bookingData['nights'] ?? 0,
            'room_type' => $bookingData['room_type'] ?? 'Standard Room',
            'roomType' => $bookingData['room_type'] ?? 'Standard Room',
            'discount' => 0 // Default discount to 0
        ];
        
        // Generate PDF if not already generated
        $pdfPath = GENERATE_BOOKING_PDF($booking['invoice_id']);
        
        // Send notification
        $notifyResult = NOTIFY::booking('stays', $customerData, $notificationData, $pdfPath);
    }
    } catch (\Throwable $e) {
        error_log("STAYS_BOOKING_WEBHOOK ERROR: " . $e->getMessage());
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

/**
 * WEBHOOK: stays.booking.hotel_viewed
 * Triggers when user views hotel details page
 * 
 * @param array $data Hotel details view information
 * @return void
 * 
 * PAYLOAD STRUCTURE:
 * - hotel_name: Hotel name
 * - hotel_id: Hotel identifier
 * - supplier: Booking source (bookingcom, expedia, etc)
 * - destination: Hotel location
 * - checkin: Check-in date
 * - checkout: Check-out date
 * - nights: Number of nights
 * - rooms: Number of rooms
 * - adults: Total adults
 * - children: Total children
 * - user_id: User ID if logged in (optional)
 * - timestamp: View timestamp
 * - session_id: Session identifier
 * - referrer: Previous page URL
 * 
 * INTEGRATION EXAMPLES:
 */
// triggerWebhook('stays/booking', 'stays.booking.hotel_viewed', $data);

// EXAMPLE 1: Track User Interest in Analytics
// -------------------------------------------------------------------
// trackEvent('hotel_viewed', [
//     'hotel_name' => $data['hotel_name'],
//     'hotel_id' => $data['hotel_id'],
//     'destination' => $data['destination'],
//     'price_range' => $data['estimated_price'] ?? null,
//     'supplier' => $data['supplier']
// ]);

// EXAMPLE 2: Trigger Retargeting Pixels
// -------------------------------------------------------------------
// triggerPixel('facebook', [
//     'event' => 'ViewContent',
//     'content_name' => $data['hotel_name'],
//     'content_category' => 'Hotel',
//     'content_ids' => [$data['hotel_id']]
// ]);

// EXAMPLE 3: Send to CRM for Lead Tracking
// -------------------------------------------------------------------
// if (!empty($data['user_id'])) {
//     $crm->updateLead($data['user_id'], [
//         'last_viewed_hotel' => $data['hotel_name'],
//         'interested_destination' => $data['destination'],
//         'view_timestamp' => $data['timestamp'],
//         'travel_dates' => "{$data['checkin']} to {$data['checkout']}"
//     ]);
// }

/**
 * WEBHOOK: stays.booking.draft_created
 * Triggers when booking draft is saved (before final confirmation)
 * 
 * @param array $data Draft booking information
 * @return void
 * 
 * PAYLOAD STRUCTURE:
 * - booking_hash: Unique booking hash identifier
 * - hotel_data: Complete hotel information (name, address, images, etc)
 * - booking_details: Dates, rooms, guests, pricing
 * - user_data: Customer information (name, email, phone, etc)
 * - total_amount: Total booking amount
 * - timestamp: Draft creation timestamp
 * - user_id: User ID if logged in (optional)
 * - session_id: Session identifier
 * 
 * INTEGRATION EXAMPLES:
 */
// triggerWebhook('stays/booking', 'stays.booking.draft_created', $data);

// EXAMPLE 1: Save to Abandoned Booking Recovery System
// -------------------------------------------------------------------
// $recoverySystem->createDraft([
//     'hash' => $data['booking_hash'],
//     'user_email' => $data['user_data']['email'],
//     'hotel' => $data['hotel_data']['name'],
//     'total' => $data['total_amount'],
//     'created_at' => $data['timestamp'],
//     'recovery_url' => "https://yoursite.com/stays/booking/{$data['booking_hash']}"
// ]);
// 
// // Schedule abandoned cart email after 1 hour
// scheduleEmail('abandoned_booking', $data['user_data']['email'], [
//     'hotel_name' => $data['hotel_data']['name'],
//     'booking_link' => "https://yoursite.com/stays/booking/{$data['booking_hash']}",
//     'expires_in' => '23 hours'
// ], '+1 hour');

// EXAMPLE 2: Track Funnel Progress
// -------------------------------------------------------------------
// trackEvent('booking_draft_created', [
//     'hotel' => $data['hotel_data']['name'],
//     'destination' => $data['hotel_data']['destination'],
//     'value' => $data['total_amount'],
//     'nights' => $data['booking_details']['nights'],
//     'rooms' => $data['booking_details']['rooms']
// ]);

// EXAMPLE 3: Send to CRM
// -------------------------------------------------------------------
// $crm->updateOpportunity([
//     'stage' => 'booking_draft',
//     'value' => $data['total_amount'],
//     'hotel' => $data['hotel_data']['name'],
//     'customer_email' => $data['user_data']['email'],
//     'customer_phone' => $data['user_data']['phone'],
//     'travel_dates' => $data['booking_details']['dates']
// ]);

/**
 * WEBHOOK: stays.booking.initiated
 * Triggers when user clicks confirm booking (before final processing)
 * 
 * @param array $data Booking initiation data
 * @return void
 * 
 * PAYLOAD STRUCTURE:
 * - booking_hash: Booking hash
 * - user_data: Customer information
 * - booking_details: Complete booking details
 * - timestamp: Initiation timestamp
 * 
 * INTEGRATION EXAMPLES:
 */
// triggerWebhook('stays/booking', 'stays.booking.initiated', $data);

// EXAMPLE: Track Conversion Funnel
// -------------------------------------------------------------------
// trackEvent('booking_initiated', [
//     'value' => $data['booking_details']['total'],
//     'hotel' => $data['booking_details']['hotel_name']
// ]);

/**
 * WEBHOOK: stays.booking.user_created
 * Triggers when a guest account is automatically created during booking
 * 
 * @param array $data New user information
 * @return void
 * 
 * PAYLOAD STRUCTURE:
 * - user_id: New user ID
 * - email: User email
 * - first_name: First name
 * - last_name: Last name
 * - phone: Phone number
 * - country: Country code
 * - created_via: 'stays_booking_guest'
 * - booking_hash: Associated booking hash
 * - timestamp: Account creation timestamp
 * 
 * INTEGRATION EXAMPLES:
 */
// triggerWebhook('stays/booking', 'stays.booking.user_created', $data);

// EXAMPLE 1: Send Welcome Email
// -------------------------------------------------------------------
// sendEmail($data['email'], 'welcome_guest_booking', [
//     'first_name' => $data['first_name'],
//     'password_reset_link' => generatePasswordResetLink($data['user_id']),
//     'booking_link' => "https://yoursite.com/stays/booking/{$data['booking_hash']}"
// ]);

// EXAMPLE 2: Add to CRM as New Lead
// -------------------------------------------------------------------
// $crm->createContact([
//     'email' => $data['email'],
//     'first_name' => $data['first_name'],
//     'last_name' => $data['last_name'],
//     'phone' => $data['phone'],
//     'country' => $data['country'],
//     'source' => 'Guest Booking',
//     'lead_score' => 50, // Higher score because they're actively booking
//     'tags' => ['guest_user', 'stays_booking']
// ]);

// EXAMPLE 3: Subscribe to Marketing Lists
// -------------------------------------------------------------------
// $mailchimp->subscribe($data['email'], [
//     'FNAME' => $data['first_name'],
//     'LNAME' => $data['last_name'],
//     'PHONE' => $data['phone'],
//     'tags' => ['guest_booking', 'hotel_customer']
// ]);

/**
 * WEBHOOK: stays.booking.confirmed
 * Triggers when booking is successfully confirmed and saved to database
 * 
 * @param array $data Confirmed booking information
 * @return void
 * 
 * PAYLOAD STRUCTURE:
 * - booking_id: Database booking ID
 * - invoice_id: Unique 8-character invoice ID
 * - user_id: Customer user ID
 * - hotel_data: Complete hotel information
 *   - name: Hotel name
 *   - address: Full address
 *   - city: City
 *   - country: Country
 *   - stars: Star rating
 *   - images: Array of image URLs
 * - booking_details:
 *   - checkin: Check-in date
 *   - checkout: Check-out date
 *   - nights: Number of nights
 *   - rooms: Number of rooms
 *   - adults: Total adults
 *   - children: Total children
 *   - room_type: Room type name
 *   - board_type: Board type (breakfast, half-board, etc)
 * - pricing:
 *   - subtotal: Base price
 *   - markup: Markup amount
 *   - tax: Tax amount
 *   - commission: Commission amount
 *   - final_total: Total amount to pay
 *   - currency: Currency code
 * - customer_data:
 *   - first_name: First name
 *   - last_name: Last name
 *   - email: Email address
 *   - phone: Phone number
 *   - country: Country code
 * - payment:
 *   - gateway: Payment gateway used
 *   - status: Payment status (unpaid/paid)
 * - module: 'stays'
 * - supplier: Hotel supplier (bookingcom, expedia, etc)
 * - timestamp: Booking confirmation timestamp
 * - invoice_url: URL to view invoice
 * 
 * INTEGRATION EXAMPLES:
 */
// triggerWebhook('stays/booking', 'stays.booking.confirmed', $data);

// EXAMPLE 1: Send to CRM - Close Deal
// -------------------------------------------------------------------
// $crm->closeOpportunity([
//     'stage' => 'won',
//     'invoice_id' => $data['invoice_id'],
//     'value' => $data['pricing']['final_total'],
//     'hotel' => $data['hotel_data']['name'],
//     'customer_email' => $data['customer_data']['email'],
//     'payment_status' => $data['payment']['status'],
//     'booking_url' => $data['invoice_url']
// ]);

// EXAMPLE 2: Track Conversion in Analytics
// -------------------------------------------------------------------
// trackEvent('booking_confirmed', [
//     'transaction_id' => $data['invoice_id'],
//     'value' => $data['pricing']['final_total'],
//     'currency' => $data['pricing']['currency'],
//     'hotel_name' => $data['hotel_data']['name'],
//     'destination' => $data['hotel_data']['city'],
//     'nights' => $data['booking_details']['nights'],
//     'rooms' => $data['booking_details']['rooms'],
//     'supplier' => $data['supplier']
// ]);
// 
// // Send to Google Analytics Enhanced Ecommerce
// trackPurchase([
//     'transaction_id' => $data['invoice_id'],
//     'value' => $data['pricing']['final_total'],
//     'currency' => $data['pricing']['currency'],
//     'items' => [[
//         'item_name' => $data['hotel_data']['name'],
//         'item_category' => 'Hotel',
//         'price' => $data['pricing']['final_total'],
//         'quantity' => 1
//     ]]
// ]);

// EXAMPLE 3: Send to Facebook Pixel
// -------------------------------------------------------------------
// triggerPixel('facebook', [
//     'event' => 'Purchase',
//     'value' => $data['pricing']['final_total'],
//     'currency' => $data['pricing']['currency'],
//     'content_name' => $data['hotel_data']['name'],
//     'content_type' => 'product',
//     'content_ids' => [$data['invoice_id']]
// ]);

// EXAMPLE 4: Notify Internal Teams
// -------------------------------------------------------------------
// sendSlackNotification('#bookings', [
//     'text' => '🎉 New Hotel Booking!',
//     'fields' => [
//         'Invoice' => $data['invoice_id'],
//         'Hotel' => $data['hotel_data']['name'],
//         'Customer' => "{$data['customer_data']['first_name']} {$data['customer_data']['last_name']}",
//         'Total' => "{$data['pricing']['currency']} {$data['pricing']['final_total']}",
//         'Dates' => "{$data['booking_details']['checkin']} - {$data['booking_details']['checkout']}",
//         'Payment' => $data['payment']['status']
//     ],
//     'actions' => [
//         ['text' => 'View Invoice', 'url' => $data['invoice_url']]
//     ]
// ]);

// EXAMPLE 5: Trigger Post-Booking Automation
// -------------------------------------------------------------------
// // Send pre-arrival emails
// scheduleEmail('pre_arrival_reminder', $data['customer_data']['email'], [
//     'hotel_name' => $data['hotel_data']['name'],
//     'checkin_date' => $data['booking_details']['checkin'],
//     'invoice_link' => $data['invoice_url']
// ], '-2 days', $data['booking_details']['checkin']);
// 
// // Schedule feedback request after checkout
// scheduleEmail('booking_feedback', $data['customer_data']['email'], [
//     'hotel_name' => $data['hotel_data']['name'],
//     'feedback_link' => "https://yoursite.com/feedback/{$data['invoice_id']}"
// ], '+1 day', $data['booking_details']['checkout']);

// EXAMPLE 6: Update Customer Profile
// -------------------------------------------------------------------
// if (!empty($data['user_id'])) {
//     $customerProfile->update($data['user_id'], [
//         'last_booking_date' => $data['timestamp'],
//         'total_bookings' => 'INCREMENT',
//         'lifetime_value' => ['ADD' => $data['pricing']['final_total']],
//         'preferred_destinations' => ['ADD' => $data['hotel_data']['city']],
//         'booking_history' => ['APPEND' => [
//             'invoice_id' => $data['invoice_id'],
//             'hotel' => $data['hotel_data']['name'],
//             'total' => $data['pricing']['final_total'],
//             'date' => $data['timestamp']
//         ]]
//     ]);
// }

/**
 * WEBHOOK: stays.booking.failed
 * Triggers when booking process fails
 * 
 * @param array $data Failure information
 * @return void
 * 
 * PAYLOAD STRUCTURE:
 * - booking_hash: Booking hash (if available)
 * - error_message: Error description
 * - error_code: Error code (if available)
 * - user_data: Customer information
 * - booking_details: Attempted booking details
 * - timestamp: Failure timestamp
 * - failure_stage: Where in process failure occurred
 * 
 * INTEGRATION EXAMPLES:
 */
// triggerWebhook('stays/booking', 'stays.booking.failed', $data);

// EXAMPLE 1: Alert Team of Critical Failures
// -------------------------------------------------------------------
// sendSlackNotification('#tech-alerts', [
//     'text' => '⚠️ Booking Failed',
//     'fields' => [
//         'Stage' => $data['failure_stage'],
//         'Error' => $data['error_message'],
//         'Customer' => $data['user_data']['email'] ?? 'Unknown',
//         'Hotel' => $data['booking_details']['hotel_name'] ?? 'Unknown',
//         'Value' => $data['booking_details']['total'] ?? 'N/A'
//     ]
// ]);

// EXAMPLE 2: Track Failed Bookings
// -------------------------------------------------------------------
// trackEvent('booking_failed', [
//     'error_message' => $data['error_message'],
//     'failure_stage' => $data['failure_stage'],
//     'value' => $data['booking_details']['total'] ?? 0
// ]);

// EXAMPLE 3: Send Apology Email with Support Link
// -------------------------------------------------------------------
// if (!empty($data['user_data']['email'])) {
//     sendEmail($data['user_data']['email'], 'booking_failed', [
//         'hotel_name' => $data['booking_details']['hotel_name'] ?? 'Hotel',
//         'support_link' => 'https://yoursite.com/support',
//         'retry_link' => "https://yoursite.com/stays/booking/{$data['booking_hash']}"
//     ]);
// }

/**
 * WEBHOOK: stays.booking.email_sent
 * Triggers when booking confirmation email is successfully sent
 * 
 * @param array $data Email sent information
 * @return void
 * 
 * PAYLOAD STRUCTURE:
 * - invoice_id: Invoice ID
 * - recipient_email: Email address
 * - recipient_name: Customer name
 * - email_type: 'booking_confirmation'
 * - attachments: Array of attached files (PDF invoice)
 * - timestamp: Email sent timestamp
 * 
 * INTEGRATION EXAMPLES:
 */
// triggerWebhook('stays/booking', 'stays.booking.email_sent', $data);

// =============================================================================
// WEBHOOK RESPONSE - Always return status array
// =============================================================================
return [
    'status' => 'success',
    'message' => "Webhook processed: {$event}"
];

// EXAMPLE 1: Log Email Delivery
// -------------------------------------------------------------------
// $emailTracker->logSent([
//     'type' => 'booking_confirmation',
//     'invoice_id' => $data['invoice_id'],
//     'recipient' => $data['recipient_email'],
//     'sent_at' => $data['timestamp'],
//     'has_attachments' => !empty($data['attachments'])
// ]);

// EXAMPLE 2: Update CRM Activity
// -------------------------------------------------------------------
// $crm->logActivity([
//     'type' => 'email_sent',
//     'subject' => 'Booking Confirmation',
//     'invoice_id' => $data['invoice_id'],
//     'recipient' => $data['recipient_email'],
//     'timestamp' => $data['timestamp']
// ]);
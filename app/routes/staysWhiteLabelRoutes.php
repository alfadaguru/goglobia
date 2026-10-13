<?php
// ============================================================================
// STAYS WHITE-LABEL ROUTES
// Guest booking form, checkout, and confirmation flows
// ============================================================================

@$SECURE or die('Access Denied!');

// Load white-label functions
require_once 'app/lib/stays_booking_functions.php';

// ============================================================================
// POST /book/{slug}/checkout
// Guest submits white-label booking form
// ============================================================================
$router->post('/book/([a-z0-9][a-z0-9-]*)/checkout', function ($slug) use ($SECURE, $db) {
    
    try {
        // Get property by slug
        $stay = $db->get('stays', ['id', 'org_id', 'name', 'slug'], 
            ['slug' => $slug, 'status' => 1, 'listing_status' => 'approved']);
        
        if (!$stay) {
            throw new Exception('Property not found or not published');
        }
        
        $stayId = (int) $stay['id'];
        $orgId = (int) $stay['org_id'];
        
        // Validate POST data
        $roomId = (int) ($_POST['room_id'] ?? 0);
        $optionId = (int) ($_POST['option_id'] ?? 0);
        $checkIn = trim((string) ($_POST['check_in_date'] ?? ''));
        $checkOut = trim((string) ($_POST['check_out_date'] ?? ''));
        $guestEmail = trim((string) ($_POST['guest_email'] ?? ''));
        $guestNameFirst = trim((string) ($_POST['guest_name_first'] ?? ''));
        $guestNameLast = trim((string) ($_POST['guest_name_last'] ?? ''));
        $guestPhone = trim((string) ($_POST['guest_phone'] ?? ''));
        $totalPrice = (float) ($_POST['total_price'] ?? 0);
        $currency = trim((string) ($_POST['currency'] ?? 'USD'));
        $specialRequests = trim((string) ($_POST['special_requests'] ?? ''));
        
        // Create stays_booking
        $bookingResult = stays_booking_create($db, [
            'org_id' => $orgId,
            'stay_id' => $stayId,
            'room_id' => $roomId,
            'option_id' => $optionId ?: null,
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'guest_email' => $guestEmail,
            'guest_name_first' => $guestNameFirst,
            'guest_name_last' => $guestNameLast,
            'guest_phone' => $guestPhone,
            'total_price' => $totalPrice,
            'currency' => $currency,
            'guest_special_requests' => $specialRequests,
        ]);
        
        if (!$bookingResult['ok']) {
            throw new Exception($bookingResult['message']);
        }
        
        $staysBookingId = (int) $bookingResult['data']['stays_booking_id'];
        $reference = $bookingResult['data']['reference'];
        
        // Create main booking record for payment
        $invoiceId = 'stays_' . bin2hex(random_bytes(6));
        $paymentIntent = $reference . '_' . bin2hex(random_bytes(4));
        
        $bookingId = $db->insert('bookings', [
            'invoice_id' => $invoiceId,
            'booking_status' => 'pending',
            'payment_status' => 'unpaid',
            'price_original' => $totalPrice,
            'currency_markup' => $currency,
            'first_name' => $guestNameFirst,
            'last_name' => $guestNameLast,
            'email' => $guestEmail,
            'phone' => $guestPhone,
            'module_type' => 'stays',
            'module' => 'stays',
            'white_label_booking_id' => $staysBookingId,
            'supplier_org_id' => $orgId,
            'supplier_booking_reference' => $reference,
            'payment_intent' => $paymentIntent,
            'special_requests' => $specialRequests,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        
        if (!$bookingId) {
            throw new Exception('Failed to create payment booking');
        }
        
        // Update stays_booking with booking_id
        $db->update('stays_bookings', 
            ['guest_booking_id' => (int) $bookingId],
            ['id' => $staysBookingId]
        );
        
        // Redirect to payment gateway
        header('Location: ' . root . 'invoice/stays/' . $invoiceId . '?token=' . urlencode($paymentIntent));
        exit;
        
    } catch (\Throwable $e) {
        error_log('White-label checkout error: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Booking error: ' . $e->getMessage()];
        header('Location: ' . root . 'book/' . $slug);
        exit;
    }
});

// ============================================================================
// GET /book/{slug}/my-booking
// Guest retrieves their booking by email + reference (no login required)
// ============================================================================
$router->get('/book/([a-z0-9][a-z0-9-]*)/my-booking', function ($slug) use ($SECURE, $db) {
    
    $stay = $db->get('stays', ['id', 'name'], 
        ['slug' => $slug, 'status' => 1, 'listing_status' => 'approved']);
    
    if (!$stay) {
        http_response_code(404);
        require_once views . "includes/header.php";
        require_once views . "404.php";
        require_once views . "includes/footer.php";
        exit;
    }
    
    $title = 'My Booking - ' . $stay['name'];
    $description = 'Retrieve your booking details';
    $header = true;
    $footer = true;
    
    // Show retrieval form
    require_once views . "includes/header.php";
    // TODO: Create stays/guest-my-booking.php view
    // This view should show:
    // 1. Email input field
    // 2. Booking reference input field
    // 3. Submit button
    // 4. On success: show booking details
    require_once views . "includes/footer.php";
});

// ============================================================================
// POST /book/{slug}/my-booking
// Guest submits email to retrieve booking
// ============================================================================
$router->post('/book/([a-z0-9][a-z0-9-]*)/my-booking', function ($slug) use ($SECURE, $db) {
    
    header('Content-Type: application/json');
    
    try {
        $email = trim((string) ($_POST['email'] ?? ''));
        $reference = trim((string) ($_POST['reference'] ?? ''));
        
        if (!$email || !$reference) {
            throw new Exception('Email and booking reference required');
        }
        
        // Find booking
        $booking = $db->get('stays_bookings', '*', [
            'guest_email' => $email,
            'reference' => $reference,
        ]);
        
        if (!$booking) {
            throw new Exception('Booking not found');
        }
        
        // Found! Return booking details
        echo json_encode([
            'ok' => true,
            'message' => 'Booking found',
            'data' => [
                'reference' => $booking['reference'],
                'guest_name' => $booking['guest_name_first'] . ' ' . $booking['guest_name_last'],
                'check_in' => $booking['check_in_date'],
                'check_out' => $booking['check_out_date'],
                'total_price' => $booking['total_price'],
                'currency' => $booking['currency'],
                'status' => $booking['status'],
                'payment_status' => $booking['payment_status'],
            ]
        ]);
        
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => $e->getMessage(),
        ]);
    }
    exit;
});


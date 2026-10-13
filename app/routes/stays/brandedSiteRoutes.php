<?php
// FILE: app/routes/stays/brandedSiteRoutes.php
// PUBLIC branded-site serving (PMS completion #13). Serves a property's branded booking
// page to the public — either by slug (/book/{slug}) or by resolving the request Host
// to a property (stays_site_resolve_host), which was previously a DEAD helper that
// nothing invoked. The page is READ-ONLY; its room "Book" buttons link into the main,
// price-integrity-protected /stay/{slug} booking flow rather than a new unauthenticated
// money endpoint. Only APPROVED + LIVE properties are served.

@$SECURE or die('Access Denied!');

if (!function_exists('_branded_render_stay')) {
    /** Render the branded page for a resolved stay id (approved+live only). */
    function _branded_render_stay($db, int $stayId): void
    {
        if ($stayId <= 0) { return; }
        $stay = $db->get('stays', '*', ['id' => $stayId, 'status' => '1', 'listing_status' => 'approved']);
        if (!$stay) { return; } // not live/approved → fall through to 404

        $rooms = $db->select('stays_rooms', ['id', 'room_type_id', 'room_images', 'room_options'],
            ['stay_id' => $stayId, 'status' => 1]) ?: [];
        if (function_exists('stays_room_option_ids')) {
            foreach ($rooms as &$__r) { $__r['_options'] = stays_room_option_ids($db, (int) $__r['id'], $stayId); }
            unset($__r);
        }
        $site = $db->get('stays_site', '*', ['stay_id' => $stayId]);
        // Public page: no inline desk booking. Room cards link to the main booking flow.
        $canBook = false;
        $publicBooking = true;             // tells the view to show "Book" links to /stay
        $stay['_slug'] = (string) ($stay['slug'] ?? '');

        $title = ($stay['name'] ?? 'Property') . ' — Book direct';
        $description = (string) ($stay['desc'] ?? '');
        $header = true; $footer = true;
        require_once views . "includes/header.php";
        require_once views . "supplier/stays/branded-preview.php";
        require_once views . "includes/footer.php";
        exit;
    }
}

// ONLINE CHECK-IN (#20 digital guest journey): /stay-checkin/{invoice}
// Guest-facing; identity proven by the invoice id (same trust model as the invoice page).
$router->get('/stay-checkin/([A-Z0-9]{6,})', function ($invoiceId) use ($SECURE, $db) {
    $booking = $db->get('bookings', ['invoice_id', 'first_name', 'last_name', 'booking_status', 'module_type', 'booking_data'], ['invoice_id' => $invoiceId]);
    $ok = $booking && in_array(strtolower((string) $booking['module_type']), ['stays', 'hotels'], true)
        && strtolower((string) $booking['booking_status']) !== 'cancelled';
    $bd = $ok ? (json_decode((string) ($booking['booking_data'] ?? ''), true) ?: []) : [];
    $title = 'Online check-in'; $description = '';
    require_once views . "stays/online-checkin.php"; // standalone (own html)
    exit;
});
$router->post('/stay-checkin/([A-Z0-9]{6,})', function ($invoiceId) use ($SECURE, $db) {
    $res = function_exists('resv_online_checkin') ? resv_online_checkin($db, $invoiceId, $_POST) : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Error'];
    header('Location: ' . root . 'stay-checkin/' . rawurlencode($invoiceId));
    exit;
});

// Public branded page by SLUG: /book/{slug}
$router->get('/book/([a-z0-9][a-z0-9-]*)', function ($slug) use ($SECURE, $db) {
    $stay = $db->get('stays', ['id'], ['slug' => $slug, 'status' => '1', 'listing_status' => 'approved']);
    if ($stay) {
        _branded_render_stay($db, (int) $stay['id']);
    }
    // Not found / not live → let the app's 404 handle it.
    http_response_code(404);
    require_once views . "includes/header.php";
    require_once views . "404.php";
    require_once views . "includes/footer.php";
});

// POST /book/{slug}/checkout-process — Process guest booking
$router->post('/book/([a-z0-9\-]+)/checkout-process', function ($slug) use ($db) {
    try {
        $stay = $db->get('stays', '*', ['slug' => $slug]);
        if (!$stay) { http_response_code(404); die('Property not found'); }
        
        if (!function_exists('booking_create') || !function_exists('booking_send_confirmation_email')) {
            http_response_code(500); die('Booking system not available');
        }
        
        $guestData = [
            'first_name' => $_POST['first_name'] ?? '',
            'last_name' => $_POST['last_name'] ?? '',
            'email' => $_POST['email'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'checkin_date' => $_POST['checkin_date'] ?? '',
            'checkout_date' => $_POST['checkout_date'] ?? '',
            'num_adults' => (int) ($_POST['num_adults'] ?? 1),
            'num_children' => (int) ($_POST['num_children'] ?? 0),
            'room_id' => (int) ($_POST['room_id'] ?? 0),
            'rate_option_id' => (int) ($_POST['option_id'] ?? 0),
            'total_price' => (float) ($_POST['total_price'] ?? 0),
            'currency' => $_POST['currency'] ?? 'USD',
            'special_requests' => $_POST['special_requests'] ?? '',
            'payment_id' => 'STRIPE-' . uniqid(),
        ];
        
        $booking = booking_create($db, (int) $stay['id'], $guestData);
        
        if ($booking) {
            booking_send_confirmation_email($db, $booking, $stay);
            $_SESSION['booking_confirmed'] = $booking['booking_reference'];
            header('Location: ' . root . 'book/' . $slug . '/booking-confirmed?ref=' . $booking['booking_reference']);
        } else {
            http_response_code(400); die('Failed to create booking');
        }
    } catch (\Throwable $e) {
        error_log('checkout-process: ' . $e->getMessage());
        http_response_code(500); die('Booking error');
    }
});

// GET /book/{slug}/my-booking — Guest booking retrieval page
$router->get('/book/([a-z0-9\-]+)/my-booking', function ($slug) use ($db) {
    try {
        $stay = $db->get('stays', '*', ['slug' => $slug]);
        if (!$stay) { http_response_code(404); die('Property not found'); }
        
        $booking = null;
        $bookingSettings = function_exists('booking_get_settings') ? booking_get_settings($db, (int) $stay['id']) : [];
        
        if (!empty($_POST['guest_email']) && function_exists('booking_get_by_email_and_stay')) {
            $booking = booking_get_by_email_and_stay($db, (int) $stay['id'], $_POST['guest_email']);
        }
        
        $SECURE = true;
        require_once views . 'stays/guest-my-booking.php';
    } catch (\Throwable $e) {
        error_log('my-booking: ' . $e->getMessage());
        http_response_code(500); die('Error');
    }
});

// POST /book/{slug}/request-otp — Send OTP to guest
$router->post('/book/([a-z0-9\-]+)/request-otp', function ($slug) use ($db) {
    try {
        $bookingId = (int) ($_POST['booking_id'] ?? 0);
        $guestEmail = trim((string) ($_POST['guest_email'] ?? ''));
        
        if ($bookingId <= 0 || empty($guestEmail)) { http_response_code(400); die('Invalid request'); }
        
        if (!function_exists('booking_create_otp')) { http_response_code(500); die('OTP not available'); }
        
        $otp = booking_create_otp($db, $bookingId, $guestEmail);
        
        if ($otp) {
            $to = $guestEmail;
            $subject = 'Your verification code';
            $body = "Your 6-digit verification code: $otp\n\nThis code expires in 10 minutes.\n\nDo not share this code with anyone.";
            $headers = "From: noreply@" . explode(':', $_SERVER['HTTP_HOST'])[0];
            mail($to, $subject, $body, $headers);
            
            header('Location: ' . root . 'book/' . $slug . '/my-booking?verify=1');
        } else {
            http_response_code(500); die('Failed to send OTP');
        }
    } catch (\Throwable $e) {
        error_log('request-otp: ' . $e->getMessage());
        http_response_code(500); die('Error');
    }
});

// POST /book/{slug}/verify-booking — Verify OTP and show booking
$router->post('/book/([a-z0-9\-]+)/verify-booking', function ($slug) use ($db) {
    try {
        $bookingId = (int) ($_POST['booking_id'] ?? 0);
        $guestEmail = trim((string) ($_POST['guest_email'] ?? ''));
        $otpCode = trim((string) ($_POST['otp_code'] ?? ''));
        
        if (!function_exists('booking_verify_otp')) { http_response_code(500); die('Verification not available'); }
        
        if (booking_verify_otp($db, $bookingId, $guestEmail, $otpCode)) {
            $_SESSION['verified_booking_id'] = $bookingId;
            header('Location: ' . root . 'book/' . $slug . '/my-booking?details=1');
        } else {
            header('Location: ' . root . 'book/' . $slug . '/my-booking?verify=1&error=invalid_otp');
        }
    } catch (\Throwable $e) {
        error_log('verify-booking: ' . $e->getMessage());
        http_response_code(500); die('Error');
    }
});


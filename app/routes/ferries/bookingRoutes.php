<?php
// ============================================================================
// FERRIES — PUBLIC BOOKING ROUTES
// ============================================================================
// GET  /ferries/booking/{hash}   — Booking form page (loaded from draft)
// ============================================================================

@$SECURE or die('Access Denied!');

// BOOKING PAGE — read draft from logs_bookings then render form
$router->get('/ferries/booking/([a-f0-9]{16})', function ($hash) use ($SECURE, $db) {

    $booking = $db->get('logs_bookings', ['hash', 'data'], ['hash' => $hash]);

    if (!$booking || empty($booking['data'])) {
        header('Location: ' . root . 'ferries');
        exit;
    }

    $bookingData = json_decode($booking['data'], true);
    if (!is_array($bookingData)) {
        header('Location: ' . root . 'ferries');
        exit;
    }

    // STORE IN SESSION SO THE BOOKING VIEW CAN ACCESS WITHOUT RE-QUERYING
    $_SESSION['booking_data'] = $bookingData;
    $_SESSION['booking_hash'] = $hash;

    $title       = T::ferry_booking . ' — ' . $GLOBALS['app']['home_title'];
    $description = '';

    require_once views . 'includes/header.php';
    require_once views . 'modules/ferries/booking/index.php';
    require_once views . 'includes/footer.php';
});

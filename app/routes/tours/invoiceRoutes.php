<?php
// ============================================================================
// FILE: app/routes/tours/invoice.php
// ============================================================================

// TOUR INVOICE ROUTE
$router->get('/invoice/tours/([a-zA-Z0-9]+)', function ($invoiceId) use ($SECURE, $db) {

    // GET BOOKING DETAILS FROM DATABASE
    $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);

    if (!$booking) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Invoice not found'
        ];
        header('Location: ' . root . 'tours');
        exit;
    }

    // CHECK BOOKING EXPIRY TIME
    $isAdmin = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin');

    if ($booking['payment_status'] !== 'paid' && !$isAdmin) {
        $expiryMinutes = $db->get('settings', 'booking_expiry_time');
        if ($expiryMinutes && is_numeric($expiryMinutes) && $expiryMinutes > 0) {
            $bookingTime = strtotime($booking['created_at']);
            $currentTime = time();
            $elapsedMinutes = ($currentTime - $bookingTime) / 60;
            
            if ($elapsedMinutes > $expiryMinutes) {
                // Trigger invoice expired webhook
                triggerWebhook('tours/invoice', 'tours.invoice.expired', [
                    'invoice_id' => $invoiceId,
                    'booking_id' => $booking['id'],
                    'user_id' => $booking['user_id'],
                    'customer_email' => $booking['email'],
                    'elapsed_minutes' => $elapsedMinutes,
                    'expiry_minutes' => $expiryMinutes,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                
                // Booking has expired, redirect to homepage
                $_SESSION['message'] = [
                    'type' => 'warning',
                    'text' => 'This booking session has expired. Please make a new booking.'
                ];
                header('Location: ' . root);
                exit;
            }
        }
    }

    // Trigger invoice viewed webhook
    triggerWebhook('tours/invoice', 'tours.invoice.viewed', [
        'invoice_id' => $invoiceId,
        'booking_id' => $booking['id'],
        'user_id' => $booking['user_id'],
        'payment_status' => $booking['payment_status'],
        'booking_status' => $booking['booking_status'],
        'total_amount' => ($booking['price_original'] ?? 0) + ($booking['price_markup'] ?? 0) + ($booking['tax'] ?? 0),
        'timestamp' => date('Y-m-d H:i:s'),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
    ]);
    
    // Check if it's a tour booking
    if ($booking['module_type'] !== 'tours') {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'This is not a tour booking invoice'
        ];
        header('Location: ' . root . 'invoice/' . $invoiceId);
        exit;
    }

    $paymentStatus = $_GET['payment_status'] ?? null;
    if ($paymentStatus) {
        require_once 'app/lib/payment-gateway.php';
        $token = $_GET['token'] ?? '';

        if (!$token) {
            $_SESSION['payment_notice'] = [
                'type' => 'error',
                'title' => 'Payment Update',
                'message' => 'Missing payment token. Please try again.'
            ];
            header('Location: ' . root . 'invoice/tours/' . $invoiceId);
            exit;
        }

        $action = in_array($paymentStatus, ['success', 'cancel', 'failure'], true) ? $paymentStatus : 'failure';
        $extra = ['gateway_data' => $_GET];

        if ($action === 'success') {
            $extra['transaction_id'] = $_GET['transaction_id'] ?? $_GET['session_id'] ?? null;
            $extra['session_id'] = $_GET['session_id'] ?? null;
        } else {
            $extra['error'] = $_GET['error'] ?? ($action === 'cancel' ? 'Payment cancelled by user' : 'Payment failed');
        }

        // Set module info with fallbacks for tours bookings
        $extra['module_type'] = $booking['module_type'] ?? 'tours'; 
        $extra['module'] = $booking['module'] ?? 'tours'; 
        
        $result = handle_payment_callback($token, $action, $extra);

        if ($action === 'success' && !empty($result['success'])) {
            $bookingResult = $result['booking'] ?? null;
            $_SESSION['payment_notice'] = [
                'type' => 'success',
                'title' => 'Payment Successful',
                'message' => $bookingResult
                    ? 'Tour Invoice #' . ($bookingResult['invoice_id'] ?? '') . ' has been paid successfully.'
                    : 'Payment completed successfully.'
            ];

            // SEND TOUR BOOKING CONFIRMATION EMAIL WITH PDF INVOICE (HANDLED BY WEBHOOK)
            /*
            try {
                // Check if email already sent for this booking
                if (!CHECK_EMAIL_SENT($invoiceId, 'booking_confirmation')) {
                    // ... (commented out logic)
                }
            } catch (Exception $e) {
                error_log("TOUR BOOKING EMAIL EXCEPTION: " . $e->getMessage() . " | Invoice: $invoiceId");
            }
            */

        } elseif ($action === 'cancel') {
            $_SESSION['payment_notice'] = [
                'type' => 'warning',
                'title' => 'Payment Cancelled',
                'message' => 'You have cancelled the payment. No charges were made.'
            ];
        } else {
            $_SESSION['payment_notice'] = [
                'type' => 'error',
                'title' => 'Payment Failed',
                'message' => $result['message'] ?? ($extra['error'] ?? 'Payment failed.')
            ];
        }

        header('Location: ' . root . 'invoice/tours/' . $invoiceId);
        exit;
    }

    // DECODE JSON FIELDS
    $bookingData = json_decode($booking['booking_data'], true);
    $travellersData = json_decode($booking['travellers'], true);
    $childAges = json_decode($booking['child_ages'], true);
    if (!is_array($bookingData)) {
        $bookingData = [];
    }
    // Older AI Trip tour bookings did not store the per-passenger totals used by
    // the normal Tours invoice. Supply safe totals so those invoices still render.
    $tourTotal = (float)($bookingData['markup_total_tour_price'] ?? $booking['price_markup'] ?? 0);
    if (!array_key_exists('markup_total_price_persons', $bookingData)) {
        $bookingData['markup_total_price_persons'] = $tourTotal;
    }
    if (!array_key_exists('markup_total_price_childrens', $bookingData)) {
        $bookingData['markup_total_price_childrens'] = 0.0;
    }
    applyInvoiceSessionCurrency($db, $_GET['currency'] ?? '', is_array($bookingData) ? $bookingData : []);

    // RENDER TOUR INVOICE PAGE
    $title = 'Tour Invoice #' . $invoiceId . ' - ' . $GLOBALS['app']['home_title'];
    $description = "Tour booking invoice for " . $booking['first_name'] . ' ' . $booking['last_name'];

    require_once views."includes/header.php";
    require_once views."modules/tours/invoice/index.php";
    require_once views."includes/footer.php";
});

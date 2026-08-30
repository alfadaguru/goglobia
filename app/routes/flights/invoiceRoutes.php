<?php
// ============================================================================
// FILE: app/routes/flights/invoice.php
// ============================================================================

// FLIGHTS INVOICE PAGE
$router->get('/invoice/flights/([a-zA-Z0-9]+)', function ($invoiceId) use ($SECURE, $db) {

    // HANDLE PAYMENT CALLBACK (if payment_status parameter present)
    if (isset($_GET['payment_status']) && isset($_GET['token'])) {
        require_once 'app/lib/payment-gateway.php';
        
        $token = $_GET['token'] ?? '';
        
        if (!$token) {
            $_SESSION['payment_notice'] = [
                'type' => 'error',
                'title' => 'Payment Update',
                'message' => 'Missing payment token. Please try again.'
            ];
            header('Location: ' . root . 'invoice/flights/' . $invoiceId);
            exit;
        }
        
        $paymentStatus = $_GET['payment_status'];
        $action = in_array($paymentStatus, ['success', 'cancel', 'failure'], true) ? $paymentStatus : 'failure';
        $extra = ['gateway_data' => $_GET];
        
        if ($action === 'success') {
            $extra['transaction_id'] = $_GET['transaction_id'] ?? $_GET['session_id'] ?? null;
            $extra['session_id'] = $_GET['session_id'] ?? null;
        } else {
            $extra['error'] = $_GET['error'] ?? ($action === 'cancel' ? 'Payment cancelled by user' : 'Payment failed');
        }
        
        // Get booking to set module info
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if ($booking) {
            $extra['module_type'] = $booking['module_type'] ?? 'flights';
            $extra['module'] = $booking['module'] ?? 'amadeus';
        }
        
        error_log("PAYMENT CALLBACK RECEIVED: Invoice: {$invoiceId}, Status: {$paymentStatus}, Token: {$token}");
        
        // Process the payment callback
        $callbackResult = handle_payment_callback($token, $action, $extra);
        
        error_log("PAYMENT CALLBACK RESULT: " . json_encode($callbackResult));
        
        // Set notification based on result
        if ($action === 'success' && !empty($callbackResult['success'])) {
            $_SESSION['payment_notice'] = [
                'type' => 'success',
                'title' => 'Payment Successful',
                'message' => 'Payment completed successfully. Your booking is being processed.'
            ];
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
                'message' => $callbackResult['message'] ?? ($extra['error'] ?? 'Payment failed.')
            ];
        }
        
        // Redirect to clean URL (remove query parameters)
        header('Location: ' . root . 'invoice/flights/' . $invoiceId);
        exit;
    }

    // GET BOOKING DETAILS FROM DATABASE
    $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);

    if (!$booking) {
        // Booking not found
        header('Location: ' . root);
        exit;
    }

    // CHECK BOOKING EXPIRY TIME - Only for unpaid bookings
    $isAdmin = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin');

    if ($booking['payment_status'] !== 'paid' && !$isAdmin) {
        $expiryMinutes = $db->get('settings', 'booking_expiry_time');
        if ($expiryMinutes && is_numeric($expiryMinutes) && $expiryMinutes > 0) {
            $bookingTime = strtotime($booking['created_at']);
            $currentTime = time();
            $elapsedMinutes = ($currentTime - $bookingTime) / 60;
            
            if ($elapsedMinutes > $expiryMinutes) {
                // Trigger invoice expired webhook
                triggerWebhook('flights/invoice', 'flights.invoice.expired', [
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
    triggerWebhook('flights/invoice', 'flights.invoice.viewed', [
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

    // DECODE JSON FIELDS
    $bookingData = json_decode($booking['booking_data'], true) ?: [];
    $travellersData = json_decode($booking['travellers'], true);
    applyInvoiceSessionCurrency($db, $_GET['currency'] ?? '', $bookingData);

    // RENDER INVOICE PAGE
    $title = 'Invoice #' . $invoiceId . ' - ' . $GLOBALS['app']['home_title'];
    $description = "Flight booking invoice for " . $booking['first_name'] . ' ' . $booking['last_name'];

    require_once views."includes/header.php";
    require_once views."modules/flights/invoice/index.php";
    require_once views."includes/footer.php";
});

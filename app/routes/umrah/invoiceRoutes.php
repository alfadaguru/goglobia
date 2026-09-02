<?php
// ============================================================================
// FILE: app/routes/umrah/invoiceRoutes.php
// ============================================================================

// UMRAH INVOICE ROUTE
$router->get('/invoice/umrah/([a-zA-Z0-9]+)', function ($invoiceId) use ($SECURE, $db) {

    // GET BOOKING DETAILS FROM DATABASE
    $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);

    if (!$booking) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Invoice not found'
        ];
        header('Location: ' . root . 'umrah');
        exit;
    }

    // SECURITY (IDOR): restrict to owner / admin / creating session.
    enforceInvoiceAccess($db, $booking, root . 'umrah');

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
                triggerWebhook('umrah/invoice', 'umrah.invoice.expired', [
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
    triggerWebhook('umrah/invoice', 'umrah.invoice.viewed', [
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
    
    // Check if it's an umrah booking
    if ($booking['module_type'] !== 'umrah') {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'This is not an umrah booking invoice'
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
            header('Location: ' . root . 'invoice/umrah/' . $invoiceId);
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

        $extra['module_type'] = $booking['module_type'] ?? 'umrah'; 
        $extra['module'] = $booking['module'] ?? 'umrah'; 
        
        $result = handle_payment_callback($token, $action, $extra);

        if ($action === 'success' && !empty($result['success'])) {
            $bookingResult = $result['booking'] ?? null;
            $_SESSION['payment_notice'] = [
                'type' => 'success',
                'title' => 'Payment Successful',
                'message' => $bookingResult
                    ? 'Umrah Invoice #' . ($bookingResult['invoice_id'] ?? '') . ' has been paid successfully.'
                    : 'Payment completed successfully.'
            ];
        } elseif (!empty($result['pending'])) {
            $_SESSION['payment_notice'] = [
                'type' => 'info',
                'title' => 'Payment Pending',
                'message' => $result['message'] ?? 'Your payment is being confirmed. Your booking will be finalised once the payment is verified.'
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
                'message' => $result['message'] ?? ($extra['error'] ?? 'Payment failed.')
            ];
        }

        header('Location: ' . root . 'invoice/umrah/' . $invoiceId);
        exit;
    }

    // DECODE JSON FIELDS
    $bookingData = json_decode($booking['booking_data'], true);
    if (!is_array($bookingData)) {
        $bookingData = [];
    }
    $travellersData = json_decode($booking['travellers'], true);
    $childAges = json_decode($booking['child_ages'], true);
    applyInvoiceSessionCurrency($db, $_GET['currency'] ?? '', $bookingData);

    // RENDER UMRAH INVOICE PAGE
    $title = 'Umrah Invoice #' . $invoiceId . ' - ' . $GLOBALS['app']['home_title'];
    $description = "Umrah booking invoice for " . $booking['first_name'] . ' ' . $booking['last_name'];

    require_once views."includes/header.php";
    require_once views."modules/umrah/invoice/index.php";
    require_once views."includes/footer.php";
});

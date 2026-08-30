<?php
// ============================================================================
// FILE: app/routes/stays/invoice.php
// ============================================================================

// INVOICE PAGE ROUTE
$router->get('/invoice/stays/([a-zA-Z0-9]+)', function ($invoiceId) use ($SECURE, $db) {

    // GET BOOKING DETAILS FROM DATABASE
    $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);

    if (!$booking) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Invoice not found'
        ];
        header('Location: ' . root . 'stays');
        exit;
    }

    // CHECK BOOKING EXPIRY TIME
    $isAdmin = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin');

    if ($booking['payment_status'] !== 'paid' && !$isAdmin) {
        $expiryMinutes = $db->get('settings', 'booking_expiry_time');
        if ($expiryMinutes && is_numeric($expiryMinutes) && $expiryMinutes > 0) {
            // Prefer created_at; fall back to booking_date. Invalid/missing timestamps
            // must not be treated as epoch (that falsely expires new bookings → home redirect).
            $bookingTimeRaw = $booking['created_at'] ?? $booking['booking_date'] ?? null;
            $bookingTime = ($bookingTimeRaw && $bookingTimeRaw !== '0000-00-00 00:00:00')
                ? strtotime((string) $bookingTimeRaw)
                : false;
            $elapsedMinutes = ($bookingTime !== false)
                ? (time() - $bookingTime) / 60
                : 0;
            
            if ($elapsedMinutes > $expiryMinutes) {
                // ============================================================================
                // WEBHOOK: Booking Expired
                // ============================================================================
                $bookingData = json_decode($booking['booking_data'], true);
                triggerWebhook('stays/invoice', 'stays.invoice.expired', [
                    'invoice_id' => $invoiceId,
                    'booking_id' => $booking['id'] ?? null,
                    'user_id' => $booking['user_id'] ?? null,
                    'hotel_name' => $bookingData['hotel_name'] ?? 'Hotel',
                    'customer_email' => $booking['email'] ?? '',
                    'total_amount' => $booking['price_markup'] ?? 0,
                    'currency' => $booking['currency_markup'] ?? 'USD',
                    'booking_created_at' => $booking['created_at'] ?? '',
                    'expiry_time_minutes' => $expiryMinutes,
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

    // ============================================================================
    // WEBHOOK: Invoice Viewed
    // ============================================================================
    $bookingData = json_decode($booking['booking_data'], true);
    if (($bookingData['source'] ?? '') === 'ai_trip') {
        require_once dirname(__DIR__) . '/ai/tripRevalidateHelper.php';
        aiTripEnsureLocalHotelPnr($db, $booking);
        $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
    }
    triggerWebhook('stays/invoice', 'stays.invoice.viewed', [
        'invoice_id' => $invoiceId,
        'booking_id' => $booking['id'] ?? null,
        'user_id' => $booking['user_id'] ?? null,
        'payment_status' => $booking['payment_status'] ?? 'unpaid',
        'total_amount' => $booking['price_markup'] ?? 0,
        'currency' => $booking['currency_markup'] ?? 'USD',
        'hotel_name' => $bookingData['hotel_name'] ?? 'Hotel',
        'customer_email' => $booking['email'] ?? '',
        'timestamp' => date('Y-m-d H:i:s'),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
    ]);

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
            header('Location: ' . root . 'invoice/' . $invoiceId);
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

        // Set module info with fallbacks for stays bookings
        $extra['module_type'] = $booking['module_type'] ?? 'stays';
        $extra['module'] = $booking['module'] ?? 'stays';

        $result = handle_payment_callback($token, $action, $extra);

        if ($action === 'success' && !empty($result['success'])) {
            $bookingResult = $result['booking'] ?? null;
            
            // ============================================================================
            // WEBHOOK: Payment Completed (HANDLED BY handle_payment_callback in app/lib/payment-gateway.php)
            // ============================================================================
            // triggerWebhook('stays/payment', 'stays.payment.completed', [...]);
            
            $_SESSION['payment_notice'] = [
                'type' => 'success',
                'title' => 'Payment Successful',
                'message' => $bookingResult
                    ? 'Invoice #' . ($bookingResult['invoice_id'] ?? '') . ' has been paid successfully.'
                    : 'Payment completed successfully.'
            ];

            // SEND BOOKING CONFIRMATION EMAIL WITH PDF INVOICE (HANDLED BY WEBHOOK)
            /*
            try {
                // Check if email already sent for this booking
                if (!CHECK_EMAIL_SENT($invoiceId, 'booking_confirmation')) {
                    // ... (rest of commented out logic)
                }
            } catch (Exception $e) {
                error_log("BOOKING EMAIL EXCEPTION: " . $e->getMessage() . " | Invoice: $invoiceId");
            }
            */

        } elseif ($action === 'cancel') {
            // ============================================================================
            // WEBHOOK: Payment Cancelled
            // ============================================================================
            triggerWebhook('stays/payment', 'stays.payment.cancelled', [
                'invoice_id' => $invoiceId,
                'booking_id' => $booking['id'] ?? null,
                'user_id' => $booking['user_id'] ?? null,
                'payment_gateway' => $booking['payment_gateway'] ?? '',
                'amount' => $booking['price_markup'] ?? 0,
                'currency' => $booking['currency_markup'] ?? 'USD',
                'customer_email' => $booking['email'] ?? '',
                'hotel_name' => $bookingData['hotel_name'] ?? 'Hotel',
                'cancellation_stage' => 'payment_page',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            $_SESSION['payment_notice'] = [
                'type' => 'warning',
                'title' => 'Payment Cancelled',
                'message' => 'You have cancelled the payment. No charges were made.'
            ];
        } else {
            // ============================================================================
            // WEBHOOK: Payment Failed
            // ============================================================================
            triggerWebhook('stays/payment', 'stays.payment.failed', [
                'invoice_id' => $invoiceId,
                'booking_id' => $booking['id'] ?? null,
                'user_id' => $booking['user_id'] ?? null,
                'payment_gateway' => $booking['payment_gateway'] ?? '',
                'payment_method' => $booking['payment_gateway'] ?? '',
                'amount' => $booking['price_markup'] ?? 0,
                'currency' => $booking['currency_markup'] ?? 'USD',
                'error_message' => $extra['error'] ?? 'Payment failed',
                'error_code' => $_GET['error_code'] ?? '',
                'customer_email' => $booking['email'] ?? '',
                'hotel_name' => $bookingData['hotel_name'] ?? 'Hotel',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            $_SESSION['payment_notice'] = [
                'type' => 'error',
                'title' => 'Payment Failed',
                'message' => $result['message'] ?? ($extra['error'] ?? 'Payment failed.')
            ];
        }

        header('Location: ' . root . 'invoice/' . $invoiceId);
        exit;
    }

    // DECODE JSON FIELDS
    $bookingData = json_decode($booking['booking_data'], true);
    $travellersData = json_decode($booking['travellers'], true);
    $childAges = json_decode($booking['child_ages'], true);
    applyInvoiceSessionCurrency($db, $_GET['currency'] ?? '', is_array($bookingData) ? $bookingData : []);

    if (!is_array($bookingData)) {
        $bookingData = [];
    }
    if (!is_array($travellersData)) {
        $travellersData = [];
    }
    if (!is_array($childAges)) {
        $childAges = [];
    }

    // AI trip (and other slim) bookings may omit invoice fields — normalize so the view never fatals
    if (empty($bookingData['nights'])) {
        $checkin = (string)($bookingData['checkin'] ?? '');
        $checkout = (string)($bookingData['checkout'] ?? '');
        $inDt = DateTime::createFromFormat('d-m-Y', $checkin) ?: DateTime::createFromFormat('Y-m-d', $checkin);
        $outDt = DateTime::createFromFormat('d-m-Y', $checkout) ?: DateTime::createFromFormat('Y-m-d', $checkout);
        $bookingData['nights'] = ($inDt && $outDt && $outDt > $inDt)
            ? max(1, (int)$inDt->diff($outDt)->days)
            : 1;
    }
    if (!isset($bookingData['selected_rooms']) || !is_array($bookingData['selected_rooms'])) {
        $bookingData['selected_rooms'] = [];
    }
    if (empty($travellersData['primary_guest']) || !is_array($travellersData['primary_guest'])) {
        $travellersData['primary_guest'] = [
            'title'        => '',
            'first_name'   => $booking['first_name'] ?? '',
            'last_name'    => $booking['last_name'] ?? '',
            'email'        => $booking['email'] ?? '',
            'phone'        => $booking['phone'] ?? '',
            'country_code' => $booking['phone_country_code'] ?? '',
        ];
    }

    // RENDER INVOICE PAGE
    $title = 'Invoice #' . $invoiceId . ' - ' . $GLOBALS['app']['home_title'];
    $description = "Booking invoice for " . $booking['first_name'] . ' ' . $booking['last_name'];

    require_once views."includes/header.php";
    require_once views."modules/stays/invoice/index.php";
    require_once views."includes/footer.php";
});

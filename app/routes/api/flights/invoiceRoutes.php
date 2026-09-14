<?php
// ============================================================================
// FILE: app/routes/web/flights/invoice.php
// ============================================================================

@$SECURE or die('Access Denied!');

// ============================================================================
// GET: Flight Invoice API
// GET /api/invoice/flights/{invoiceId}
// ============================================================================
$router->get('/api/invoice/flights/([a-zA-Z0-9]+)', function ($invoiceId) use ($db) {

    header('Content-Type: application/json');

    // =========================================================================
    // HANDLE PAYMENT CALLBACK 
    // =========================================================================
    if (isset($_GET['payment_status']) && isset($_GET['token'])) {

        require_once 'app/lib/payment-gateway.php';

        $token = $_GET['token'] ?? '';
        $paymentStatus = $_GET['payment_status'] ?? 'failure';

        if (!$token) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Missing payment token'
            ]);
            exit;
        }

        $action = in_array($paymentStatus, ['success','cancel','failure'], true)
            ? $paymentStatus
            : 'failure';

        $extra = ['gateway_data' => $_GET];

        if ($action === 'success') {
            $extra['transaction_id'] = $_GET['transaction_id'] ?? $_GET['session_id'] ?? null;
            $extra['session_id'] = $_GET['session_id'] ?? null;
        } else {
            $extra['error'] = $_GET['error']
                ?? ($action === 'cancel' ? 'Payment cancelled by user' : 'Payment failed');
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if ($booking) {
            $extra['module_type'] = $booking['module_type'] ?? 'flights';
            $extra['module'] = $booking['module'] ?? 'amadeus';
        }

        error_log("FLIGHT PAYMENT CALLBACK | Invoice: {$invoiceId} | Status: {$paymentStatus}");

        $callbackResult = handle_payment_callback($token, $action, $extra);

        echo json_encode([
            'success' => (bool)($callbackResult['success'] ?? false),
            'payment_status' => $action,
            'message' => $callbackResult['message']
                ?? ($action === 'success'
                    ? 'Payment successful'
                    : ($action === 'cancel'
                        ? 'Payment cancelled'
                        : 'Payment failed')),
            'invoice_id' => $invoiceId
        ]);
        exit;
    }

    // =========================================================================
    // GET BOOKING DETAILS
    // =========================================================================
    $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);

    if (!$booking) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Booking not found'
        ]);
        exit;
    }

    // IDOR GUARD: this returns customer PII + pricing. Restrict to admin /
    // owner / creating session / valid payment token. enforceInvoiceAccess()
    // emits 403 JSON and exits on an /api/ route for a non-owner. Was
    // previously unauthenticated.
    if (function_exists('enforceInvoiceAccess')) {
        enforceInvoiceAccess($db, $booking);
    }

    // =========================================================================
    // CHECK BOOKING EXPIRY
    // =========================================================================
    if ($booking['payment_status'] !== 'paid') {

        $expiryMinutes = $db->get('settings', 'booking_expiry_time');

        if ($expiryMinutes && is_numeric($expiryMinutes) && $expiryMinutes > 0) {

            $bookingTime = strtotime($booking['created_at']);
            $elapsedMinutes = (time() - $bookingTime) / 60;

            if ($elapsedMinutes > $expiryMinutes) {

                triggerWebhook('flights/invoice', 'flights.invoice.expired', [
                    'invoice_id' => $invoiceId,
                    'booking_id' => $booking['id'],
                    'user_id' => $booking['user_id'],
                    'customer_email' => $booking['email'],
                    'elapsed_minutes' => $elapsedMinutes,
                    'expiry_minutes' => $expiryMinutes,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);

                http_response_code(410);
                echo json_encode([
                    'success' => false,
                    'message' => 'Booking session expired'
                ]);
                exit;
            }
        }
    }

    // =========================================================================
    // INVOICE VIEWED WEBHOOK
    // =========================================================================
    triggerWebhook('flights/invoice', 'flights.invoice.viewed', [
        'invoice_id' => $invoiceId,
        'booking_id' => $booking['id'],
        'user_id' => $booking['user_id'],
        'payment_status' => $booking['payment_status'],
        'booking_status' => $booking['booking_status'],
        'total_amount' =>
            ($booking['price_original'] ?? 0) +
            ($booking['price_markup'] ?? 0) +
            ($booking['tax'] ?? 0),
        'timestamp' => date('Y-m-d H:i:s'),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
    ]);

    // =========================================================================
    // PREPARE RESPONSE DATA 
    // =========================================================================
    $bookingData    = json_decode($booking['booking_data'], true);
    $travellersData = json_decode($booking['travellers'], true);

    echo json_encode([
        'success' => true,
        'invoice_id' => $invoiceId,
        'payment_status' => $booking['payment_status'],
        'booking_status' => $booking['booking_status'],
        'customer' => [
            'first_name' => $booking['first_name'],
            'last_name'  => $booking['last_name'],
            'email'      => $booking['email'],
            'phone'      => $booking['phone']
        ],
        'pricing' => [
            'base_price'  => $booking['price_original'],
            'tax'         => $booking['tax'],
            'final_total' => $booking['price_markup'],
            'currency'    => $booking['currency_markup']
        ],
        'booking_data' => $bookingData,
        'travellers' => $travellersData,
        'created_at' => $booking['created_at']
    ]);
    exit;
});

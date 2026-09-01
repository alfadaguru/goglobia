<?php
// ============================================================================
// FERRIES — PUBLIC INVOICE ROUTE
// ============================================================================
@$SECURE or die('Access Denied!');

$router->get('/invoice/ferries/([a-zA-Z0-9]+)', function ($invoiceId) use ($SECURE, $db) {

    // HANDLE PAYMENT CALLBACK
    if (isset($_GET['payment_status']) && isset($_GET['token'])) {
        require_once 'app/lib/payment-gateway.php';

        $token        = $_GET['token'] ?? '';
        $paymentStatus = $_GET['payment_status'];
        $action       = in_array($paymentStatus, ['success', 'cancel', 'failure'], true) ? $paymentStatus : 'failure';
        $extra        = ['gateway_data' => $_GET];

        if ($action === 'success') {
            $extra['transaction_id'] = $_GET['transaction_id'] ?? $_GET['session_id'] ?? null;
            $extra['session_id']     = $_GET['session_id'] ?? null;
        } else {
            $extra['error'] = $_GET['error'] ?? ($action === 'cancel' ? 'Payment cancelled by user' : 'Payment failed');
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if ($booking) {
            $extra['module_type'] = $booking['module_type'] ?? 'ferries';
            $extra['module']      = $booking['module']      ?? 'kikoto';
        }

        $callbackResult = handle_payment_callback($token, $action, $extra);

        if ($action === 'success' && !empty($callbackResult['success'])) {
            $_SESSION['payment_notice'] = [
                'type'    => 'success',
                'title'   => 'Payment Successful',
                'message' => 'Payment completed. Your ferry ticket is being issued.',
            ];
        } elseif (!empty($callbackResult['pending'])) {
            $_SESSION['payment_notice'] = [
                'type'    => 'info',
                'title'   => 'Payment Pending',
                'message' => $callbackResult['message'] ?? 'Your payment is being confirmed. Your booking will be finalised once the payment is verified.',
            ];
        } elseif ($action === 'cancel') {
            $_SESSION['payment_notice'] = [
                'type'    => 'warning',
                'title'   => 'Payment Cancelled',
                'message' => 'You cancelled the payment. No charges were made.',
            ];
        } else {
            $_SESSION['payment_notice'] = [
                'type'    => 'error',
                'title'   => 'Payment Failed',
                'message' => $callbackResult['message'] ?? ($extra['error'] ?? 'Payment failed.'),
            ];
        }

        header('Location: ' . root . 'invoice/ferries/' . $invoiceId);
        exit;
    }

    // FETCH BOOKING
    $booking = $db->get('bookings', '*', [
        'invoice_id'  => $invoiceId,
        'module_type' => 'ferries',
    ]);

    if (!$booking) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Invoice not found'];
        header('Location: ' . root . 'ferries');
        exit;
    }

    // SECURITY (IDOR): restrict to owner / admin / creating session.
    enforceInvoiceAccess($db, $booking, root . 'ferries');

    // BOOKING EXPIRY CHECK (unpaid, non-admin)
    $isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
    if ($booking['payment_status'] !== 'paid' && !$isAdmin) {
        $expiryMinutes = $db->get('settings', 'booking_expiry_time');
        if ($expiryMinutes && is_numeric($expiryMinutes) && (int)$expiryMinutes > 0) {
            $elapsed = (time() - strtotime($booking['created_at'])) / 60;
            if ($elapsed > (int)$expiryMinutes) {
                $_SESSION['message'] = ['type' => 'error', 'text' => 'Booking has expired'];
                header('Location: ' . root . 'ferries');
                exit;
            }
        }
    }

    $bookingData = json_decode($booking['booking_data'] ?? '{}', true) ?: [];
    applyInvoiceSessionCurrency($db, $_GET['currency'] ?? '', $bookingData);
    $title       = (T::ferry_invoice ?? 'Ferry Invoice') . ' #' . $invoiceId . ' — ' . $GLOBALS['app']['home_title'];
    $description = '';

    require_once views . 'includes/header.php';
    require_once views . 'modules/ferries/invoice/index.php';
    require_once views . 'includes/footer.php';
});

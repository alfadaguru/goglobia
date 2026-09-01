<?php
@$SECURE or die('Access Denied!');

if (!function_exists('applyInvoiceSessionCurrency')) {
    require_once dirname(__DIR__, 2) . '/lib/functions.php';
}

// 1. TRAIN BOOKING INVOICE PAGE
$router->get('/invoice/rail/([a-zA-Z0-9]+)', function ($invoiceId) use ($SECURE, $db) {
    $booking = $db->get('bookings', '*', [
        'invoice_id' => $invoiceId,
        'module_type' => 'rail'
    ]);

    if (!$booking) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Invoice not found'];
        header('Location: ' . root . 'rail');
        exit;
    }

    // SECURITY (IDOR): restrict to owner / admin / creating session.
    enforceInvoiceAccess($db, $booking, root . 'rail');

    // ============================================================================
    // PAYMENT GATEWAY REDIRECT-BACK HANDLING
    // ============================================================================
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
            header('Location: ' . root . 'invoice/rail/' . $invoiceId);
            exit;
        }

        $action = in_array($paymentStatus, ['success', 'cancel', 'failure'], true) ? $paymentStatus : 'failure';
        $extra  = ['gateway_data' => $_GET];

        if ($action === 'success') {
            $extra['transaction_id'] = $_GET['transaction_id'] ?? $_GET['session_id'] ?? null;
            $extra['session_id']     = $_GET['session_id'] ?? null;
        } else {
            $extra['error'] = $_GET['error'] ?? ($action === 'cancel' ? 'Payment cancelled by user' : 'Payment failed');
        }

        $extra['module_type'] = $booking['module_type'] ?? 'rail';
        $extra['module']      = $booking['module'] ?? 'train';

        $result = handle_payment_callback($token, $action, $extra);

        if ($action === 'success' && !empty($result['success'])) {
            require_once dirname(__DIR__, 3) . '/modules/rail/train/search.php';
            _train_issue_rail_after_payment($db, $invoiceId);
            $bookingResult = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'rail']);
            $_SESSION['payment_notice'] = [
                'type' => 'success',
                'title' => 'Payment Successful',
                'message' => $bookingResult
                    ? 'Invoice #' . ($bookingResult['invoice_id'] ?? '') . ' has been paid successfully.'
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

        header('Location: ' . root . 'invoice/rail/' . $invoiceId);
        exit;
    }

    $bookingData = json_decode($booking['booking_data'] ?? '[]', true) ?: [];
    applyInvoiceSessionCurrency($db, $_GET['currency'] ?? '', $bookingData);

    $title = 'Train Booking Invoice #' . $invoiceId . ' | ' . $GLOBALS['app']['home_title'];
    require_once views . "includes/header.php";
    require_once views . "modules/rail/invoice/index.php";
    require_once views . "includes/footer.php";
});

<?php
// ============================================================================
// FILE: app/routes/cruises/invoiceRoutes.php
// CRUISE INVOICE / PAY page (own-inventory — Phase 2). GET /invoice/cruises/{id}.
// Mirrors the insurance invoice route: fetch booking, enforce IDOR, handle the
// post-payment gateway callback via handle_payment_callback(), then render the shared
// invoice summary (which drives gateway selection -> /payment/process -> settlement).
// ============================================================================

@$SECURE or die('Access Denied!');

$router->get('/invoice/cruises/([a-zA-Z0-9]+)', function ($invoiceId) use ($SECURE, $db) {
    $booking = $db->get('bookings', '*', [
        'OR' => ['id' => $invoiceId, 'invoice_id' => $invoiceId],
        'module_type' => 'cruises',
    ]);
    if (!$booking) {
        http_response_code(404);
        $title = 'Invoice Not Found'; $description = '';
        require_once views . "includes/header.php";
        require_once views . "404.php";
        require_once views . "includes/footer.php";
        return;
    }
    $invoiceId = $booking['invoice_id'];

    // IDOR: owner / admin / creating session only (same guard as every invoice).
    if (function_exists('enforceInvoiceAccess')) {
        enforceInvoiceAccess($db, $booking, root . 'bookings');
    }

    // Post-payment gateway callback (success/cancel/failure).
    $paymentStatus = $_GET['payment_status'] ?? null;
    if ($paymentStatus) {
        require_once 'app/lib/payment-gateway.php';
        $token = $_GET['token'] ?? '';
        if ($token !== '') {
            $action = in_array($paymentStatus, ['success', 'cancel', 'failure'], true) ? $paymentStatus : 'failure';
            $result = handle_payment_callback($token, $action, [
                'gateway'        => $booking['payment_method'] ?? 'unknown',
                'transaction_id' => $_GET['transaction_id'] ?? $_GET['session_id'] ?? null,
                'module_type'    => 'cruises',
                'module'         => $booking['module'] ?? 'goglobia',
            ]);
            if (!empty($result['pending'])) {
                $_SESSION['success'] = $result['message'] ?? 'Your payment is being confirmed.';
            } elseif ($action === 'success') {
                $_SESSION[($result['success'] ?? false) ? 'success' : 'error'] =
                    ($result['success'] ?? false) ? 'Payment successful! Your cruise is confirmed.'
                    : ('Payment successful but confirmation is pending: ' . ($result['message'] ?? ''));
            } elseif ($action === 'cancel') {
                $_SESSION['error'] = 'Payment was cancelled.';
            } else {
                $_SESSION['error'] = 'Payment failed. Please try again.';
            }
            header('Location: ' . root . 'invoice/cruises/' . $invoiceId);
            exit;
        }
    }

    $bookingData = json_decode((string) ($booking['booking_data'] ?? ''), true) ?: [];
    $title = 'Cruise booking ' . $invoiceId;
    $description = '';
    require_once views . "includes/header.php";
    require_once views . "includes/invoice/summary.php";
    require_once views . "includes/footer.php";
});

<?php
// ============================================================================
// AI trip INVOICE route (shared ai_trip booking spine — used by GIA + the cart).
// The old /ai-trip planner UI (search page, cart, booking wizard, confirmation)
// was retired when GIA became the only AI surface. This file now only serves the
// invoice/payment-callback page for ai_trip bookings; GIA + cartRoutes create them.
// ============================================================================
@$SECURE or die("Access Denied!");


// Package invoice (AI trip only — does not affect flights/stays/cars invoice routes)
$router->get('/invoice/ai_trip/([A-Za-z0-9]+)', function ($invoiceId) use ($SECURE, $db) {
    $invoiceId = preg_replace('/[^A-Za-z0-9]/', '', (string)$invoiceId);

    // Payment callback (same pattern as cars/flights)
    $paymentStatus = $_GET['payment_status'] ?? null;
    if ($paymentStatus && isset($_GET['token'])) {
        require_once 'app/lib/payment-gateway.php';
        $token = (string)($_GET['token'] ?? '');
        if ($token === '') {
            $_SESSION['payment_notice'] = [
                'type' => 'error',
                'title' => 'Payment Update',
                'message' => 'Missing payment token. Please try again.',
            ];
            header('Location: ' . root . 'invoice/ai_trip/' . $invoiceId);
            exit;
        }

        $action = in_array($paymentStatus, ['success', 'cancel', 'failure'], true) ? $paymentStatus : 'failure';
        $bookingLookup = $db->get('bookings', '*', [
            'invoice_id' => $invoiceId,
            'module_type' => 'ai_trip',
        ]);
        $extra = [
            'gateway' => $bookingLookup['payment_method'] ?? ($bookingLookup['payment_gateway'] ?? 'unknown'),
            'transaction_id' => $_GET['transaction_id'] ?? ($_GET['session_id'] ?? null),
            'session_id' => $_GET['session_id'] ?? null,
            'module_type' => 'ai_trip',
            'module' => $bookingLookup['module'] ?? 'ai_trip',
            'gateway_data' => $_GET,
        ];
        if ($action !== 'success') {
            $extra['error'] = $_GET['error'] ?? ($action === 'cancel' ? 'Payment cancelled by user' : 'Payment failed');
        }

        $result = handle_payment_callback($token, $action, $extra);

        if ($action === 'success' && !empty($result['success'])) {
            $_SESSION['payment_notice'] = [
                'type' => 'success',
                'title' => 'Payment Successful',
                'message' => 'Payment completed successfully. Your AI trip package is being processed.',
            ];
        } elseif (!empty($result['pending'])) {
            $_SESSION['payment_notice'] = [
                'type' => 'info',
                'title' => 'Payment Pending',
                'message' => $result['message'] ?? 'Your payment is being confirmed. Your AI trip package will be finalised once the payment is verified.',
            ];
        } elseif ($action === 'cancel') {
            $_SESSION['payment_notice'] = [
                'type' => 'warning',
                'title' => 'Payment Cancelled',
                'message' => 'You have cancelled the payment. No charges were made.',
            ];
        } else {
            $_SESSION['payment_notice'] = [
                'type' => 'error',
                'title' => 'Payment Failed',
                'message' => $result['message'] ?? ($extra['error'] ?? 'Payment failed.'),
            ];
        }

        header('Location: ' . root . 'invoice/ai_trip/' . $invoiceId);
        exit;
    }

    $booking = $db->get('bookings', '*', [
        'OR' => [
            'id' => $invoiceId,
            'invoice_id' => $invoiceId,
        ],
        'module_type' => 'ai_trip',
    ]);

    if (!$booking) {
        http_response_code(404);
        $title = 'Invoice Not Found';
        $description = 'The requested invoice could not be found';
        $header = true;
        $footer = true;
        require_once views . 'includes/header.php';
        require_once views . 'errors/404.php';
        require_once views . 'includes/footer.php';
        exit;
    }

    // SECURITY (IDOR): restrict to owner / admin / creating session.
    enforceInvoiceAccess($db, $booking, root . 'gia');

    $invoiceId = $booking['invoice_id'];

    require_once __DIR__ . '/tripRevalidateHelper.php';
    aiTripEnsureLocalHotelPnr($db, $booking);

    // Owner check when logged in (same as cars)
    if (isset($_SESSION['user_id']) && !empty($booking['user_id'])) {
        $isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
        if (!$isAdmin && (string)$booking['user_id'] !== (string)$_SESSION['user_id']) {
            $_SESSION['error'] = 'Unauthorized access';
            header('Location: ' . root . 'bookings');
            exit;
        }
    }

    $title = 'AI Trip Invoice #' . $invoiceId;
    $description = 'View your AI trip package invoice';
    $header = true;
    $footer = true;

    require_once views . 'includes/header.php';
    require_once views . 'ai/invoice.php';
    require_once views . 'includes/footer.php';
});

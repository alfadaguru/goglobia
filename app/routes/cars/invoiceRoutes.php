<?php
// FILE: app/routes/cars/invoice.php
// Car booking invoice routes

@$SECURE or die('Access Denied!');

// ====================================
// CAR BOOKING INVOICE
// ====================================

$router->get('/invoice/cars/([a-zA-Z0-9]+)', function ($invoiceId) use ($SECURE, $db) {
    
    // Fetch booking (support both ID and invoice_id)
    $booking = $db->get('bookings', '*', [
        'OR' => [
            'id' => $invoiceId,
            'invoice_id' => $invoiceId
        ],
        'module_type' => 'cars'
    ]);
                                  
    if (!$booking) {
        http_response_code(404);
        $title = "Invoice Not Found";
        $description = "The requested invoice could not be found";
        $header = true;
        $footer = true;
        require_once views."includes/header.php";
        require_once views."errors/404.php";
        require_once views."includes/footer.php";
        exit;
    }

    // SECURITY (IDOR): restrict to owner / admin / creating session.
    enforceInvoiceAccess($db, $booking, root . 'cars');

    $id = $booking['id'];
    $invoiceId = $booking['invoice_id'];

    // Handle payment callback
    $paymentStatus = $_GET['payment_status'] ?? null;
    if ($paymentStatus) {
        require_once 'app/lib/payment-gateway.php';
        $token = $_GET['token'] ?? '';
        
        if (!$token) {
            $_SESSION['error'] = 'Missing payment token. Please try again.';
            header('Location: ' . root . 'invoice/cars/' . $invoiceId);
            exit;
        }

        $action = in_array($paymentStatus, ['success', 'cancel', 'failure'], true) ? $paymentStatus : 'failure';
        $extra = [
            'gateway' => $booking['payment_method'] ?? 'unknown',
            'transaction_id' => $_GET['transaction_id'] ?? $_GET['session_id'] ?? null,
            'session_id' => $_GET['session_id'] ?? null,
            'module_type' => 'cars',
            'module' => $booking['module'] ?? 'cars'
        ];

        $result = handle_payment_callback($token, $action, $extra);

        if (!empty($result['pending'])) {
            // Payment awaiting gateway/webhook verification — not confirmed, not failed.
            $_SESSION['success'] = $result['message'] ?? 'Your payment is being confirmed. Your car booking will be finalised once the payment is verified.';
            header('Location: ' . root . 'invoice/cars/' . $invoiceId);
            exit;
        } elseif ($action === 'success') {
            if ($result['success'] ?? false) {
                if (!empty($result['requires_mozio_payment'])) {
                    $_SESSION['success'] = 'Payment received. Complete Mozio checkout to confirm your transfer booking.';
                } else {
                    $_SESSION['success'] = 'Payment successful! Your car booking is confirmed.';
                }
            } else {
                $_SESSION['error'] = 'Payment successful but booking confirmation failed: ' . ($result['message'] ?? 'Unknown error');
            }

            // Prefer Mozio Stripe URL when issue left a hosted-checkout redirect.
            $fresh = $db->get('bookings', ['booking_data', 'pnr', 'module'], ['invoice_id' => $invoiceId]);
            $freshData = json_decode($fresh['booking_data'] ?? '{}', true) ?: [];
            $mozioPayUrl = trim((string)($freshData['mozio']['stripe_redirect_url'] ?? ''));
            if (
                strtolower((string)($fresh['module'] ?? '')) === 'mozio'
                && empty($fresh['pnr'])
                && $mozioPayUrl !== ''
            ) {
                require_once dirname(__DIR__, 3) . '/modules/cars/mozio/lib.php';
                mozioRememberPendingCheckout($invoiceId, (string)($freshData['mozio']['search_id'] ?? ''));
                header('Location: ' . $mozioPayUrl);
                exit;
            }

            $redirectTarget = paymentReturnUrl($result['redirect_url'] ?? urlOnCurrentHost('invoice/cars/' . $invoiceId));
            header('Location: ' . $redirectTarget);
            exit;
        } elseif ($action === 'cancel') {
            $_SESSION['error'] = 'Payment was cancelled.';
        } else {
            $_SESSION['error'] = 'Payment failed. Please try again.';
        }

        if ($action !== 'success') {
            header('Location: ' . root . 'invoice/cars/' . $invoiceId);
            exit;
        }
    }

    // Trigger webhook - invoice viewed
    triggerWebhook('cars/invoice', 'cars.invoice.viewed', [
        'booking_id' => $id,
        'invoice_id' => $invoiceId,
        'user_id' => $_SESSION['user_id'] ?? null,
        'booking_status' => $booking['booking_status'],
        'payment_status' => $booking['payment_status'] ?? 'pending',
        'timestamp' => date('Y-m-d H:i:s')
    ]);

    // META DATA
    $bookingData = json_decode($booking['booking_data'] ?? '[]', true) ?: [];
    applyInvoiceSessionCurrency($db, $_GET['currency'] ?? '', $bookingData);

    // Mozio hosted checkout: send customer to Mozio Stripe once, then stay on
    // this invoice and poll after they return (?mozio_return=1 or session flag).
    $mozioMeta = is_array($bookingData['mozio'] ?? null) ? $bookingData['mozio'] : [];
    $mozioStripeUrl = trim((string)($mozioMeta['stripe_redirect_url'] ?? ''));
    $isMozioBooking = strtolower((string)($booking['module'] ?? '')) === 'mozio';
    $isMozioPending = $isMozioBooking
        && empty($booking['pnr'])
        && $mozioStripeUrl !== '';

    $mozioHostedCheckout = false;
    if ($isMozioBooking) {
        require_once dirname(__DIR__, 3) . '/modules/cars/mozio/lib.php';
        $mozioHostedCheckout = function_exists('mozioIsHostedCheckout') && mozioIsHostedCheckout($db);
    }

    if ($isMozioPending) {
        $returning = isset($_GET['mozio_return'])
            || !empty($_SESSION['mozio_checkout_sent'][$invoiceId]);

        if (!$returning) {
            require_once dirname(__DIR__, 3) . '/modules/cars/mozio/lib.php';
            mozioRememberPendingCheckout($invoiceId, (string)($mozioMeta['search_id'] ?? ''));
            header('Location: ' . $mozioStripeUrl);
            exit;
        }
    }

    $title = "Car Booking Invoice #" . $invoiceId;
    $description = "View your car rental booking invoice";
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once views."modules/cars/invoice/index.php";
    require_once views."includes/footer.php";
});

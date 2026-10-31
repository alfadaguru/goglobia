<?php

// app/routes/insurance/invoiceRoutes.php
// Flight-compensation-claim confirmation / status page.
@$SECURE or die('Access Denied!');

$router->get('/insurance/claim/([A-Z0-9]{8})', function ($invoiceId) use ($SECURE, $db) {
    $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'insurance']);

    if (!$booking) {
        http_response_code(404);
        $title = 'Claim not found';
        $description = '';
        require_once views . "includes/header.php";
        require_once views . "404.php";
        require_once views . "includes/footer.php";
        return;
    }
    // (view path set below after ownership check)

    // Ownership check (avoid the invoice-IDOR pattern flagged in the security
    // audit C3): a logged-in non-admin may only view their own claim. Guests can
    // view via the exact 8-char invoice id they were given (no PII beyond what
    // they submitted). Admins see everything.
    $isAdmin  = (($_SESSION['user_role'] ?? '') === 'admin');
    $ownerId  = $booking['user_id'] ?? null;
    $sessUser = $_SESSION['user_id'] ?? null;
    if (!$isAdmin && $ownerId && $sessUser && (string) $ownerId !== (string) $sessUser) {
        http_response_code(403);
        echo 'Access denied.';
        return;
    }

    $claimData = json_decode((string) ($booking['booking_data'] ?? ''), true) ?: [];

    $title = 'Flight Compensation Claim ' . $invoiceId;
    $description = '';
    require_once views . "includes/header.php";
    require_once views . "modules/insurance/claim.php";
    require_once views . "includes/footer.php";
});

// ---------------------------------------------------------------------------
// GET /invoice/insurance/{id} — PAID travel-insurance POLICY invoice (scaffolding).
// The standard paid-booking invoice (distinct from the free /insurance/claim/{id}
// page). Mirrors the eSIM invoice route: fetch booking, enforce ownership, handle
// the post-payment gateway callback, then render a policy invoice via the shared
// invoice summary include. Reached by the payment-gateway callback URL
// (invoice/insurance/, wired in globalRoutes) and by a paid-insurance booking.
// ---------------------------------------------------------------------------
$router->get('/invoice/insurance/([a-zA-Z0-9]+)', function ($invoiceId) use ($SECURE, $db) {
    $booking = $db->get('bookings', '*', [
        'OR' => ['id' => $invoiceId, 'invoice_id' => $invoiceId],
        'module_type' => 'insurance',
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

    // Post-payment gateway callback (success/cancel/failure) — mirrors eSIM.
    $paymentStatus = $_GET['payment_status'] ?? null;
    if ($paymentStatus) {
        require_once 'app/lib/payment-gateway.php';
        $token = $_GET['token'] ?? '';
        if ($token !== '') {
            $action = in_array($paymentStatus, ['success', 'cancel', 'failure'], true) ? $paymentStatus : 'failure';
            $result = handle_payment_callback($token, $action, [
                'gateway'        => $booking['payment_method'] ?? 'unknown',
                'transaction_id' => $_GET['transaction_id'] ?? $_GET['session_id'] ?? null,
                'module_type'    => 'insurance',
                'module'         => $booking['module'] ?? 'travelsure',
            ]);
            if (!empty($result['pending'])) {
                $_SESSION['success'] = $result['message'] ?? 'Your payment is being confirmed.';
            } elseif ($action === 'success') {
                $_SESSION[($result['success'] ?? false) ? 'success' : 'error'] =
                    ($result['success'] ?? false) ? 'Payment successful! Your policy is being issued.'
                    : ('Payment successful but issuance is pending: ' . ($result['message'] ?? ''));
            } elseif ($action === 'cancel') {
                $_SESSION['error'] = 'Payment was cancelled.';
            } else {
                $_SESSION['error'] = 'Payment failed. Please try again.';
            }
            header('Location: ' . root . 'invoice/insurance/' . $invoiceId);
            exit;
        }
    }

    $bookingData = json_decode((string) ($booking['booking_data'] ?? ''), true) ?: [];
    $title = 'Travel Insurance ' . $invoiceId;
    $description = '';
    require_once views . "includes/header.php";
    require_once views . "includes/invoice/summary.php";
    require_once views . "includes/footer.php";
});

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

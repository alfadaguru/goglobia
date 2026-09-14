<?php
// ============================================================================
// FILE: app/routes/visa/invoiceRoutes.php
// ============================================================================

// INVOICE PAGE ROUTE
$router->get('/invoice/visa/([a-zA-Z0-9]+)', function ($invoiceId) use ($SECURE, $db) {

    // GET BOOKING DETAILS FROM DATABASE
    $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);

    if (!$booking) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Invoice not found'
        ];
        header('Location: ' . root . 'visa');
        exit;
    }

    // SECURITY (IDOR): restrict to owner / admin / creating session.
    enforceInvoiceAccess($db, $booking, root . 'visa');

    // ============================================================================
    // WEBHOOK: Invoice Viewed
    // ============================================================================
    $bookingData = json_decode($booking['booking_data'], true);
    triggerWebhook('visa/invoice', 'visa.invoice.viewed', [
        'invoice_id' => $invoiceId,
        'booking_id' => $booking['id'] ?? null,
        'user_id' => $booking['user_id'] ?? null,
        'payment_status' => $booking['payment_status'] ?? 'unpaid',
        'total_amount' => $booking['price_markup'] ?? 0,
        'currency' => $booking['currency_markup'] ?? 'USD',
        'customer_email' => $booking['email'] ?? '',
        'timestamp' => date('Y-m-d H:i:s'),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
    ]);

    // DECODE JSON FIELDS
    $bookingData = json_decode($booking['booking_data'], true);
    $travellersData = json_decode($booking['travellers'], true);
    $childAges = json_decode($booking['child_ages'], true);
    applyInvoiceSessionCurrency($db, $_GET['currency'] ?? '', is_array($bookingData) ? $bookingData : []);

    // RENDER INVOICE PAGE
    $title = 'Visa Invoice #' . $invoiceId . ' - ' . $GLOBALS['app']['home_title'];
    $description = "Visa inquiry invoice for " . $booking['first_name'] . ' ' . $booking['last_name'];

    require_once views."includes/header.php";
    require_once views."modules/visa/invoice/index.php";
    require_once views."includes/footer.php";
});

// ============================================================================
// GET: Download invoice PDF
// GET /api/visa/booking/download-invoice/{invoiceId}
// ============================================================================
$router->get('/api/visa/booking/download-invoice/([a-zA-Z0-9]+)', function ($invoiceId) use ($SECURE, $db) {
    try {
        // Check if booking exists
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) {
            http_response_code(404);
            die('Booking not found');
        }

        // IDOR GUARD: the PDF contains customer PII + pricing. Restrict to
        // admin / owner / creating session / valid payment token. Was
        // previously unauthenticated. enforceInvoiceAccess() exits on denial.
        if (function_exists('enforceInvoiceAccess')) {
            enforceInvoiceAccess($db, $booking);
        }

        // Always generate/refresh PDF before download
        $pdfPath = GENERATE_BOOKING_PDF($invoiceId);

        if ($pdfPath && file_exists($pdfPath)) {
            // Serve PDF
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="invoice_' . $invoiceId . '.pdf"');
            header('Content-Length: ' . filesize($pdfPath));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            readfile($pdfPath);
            exit;
        }

        throw new Exception('Failed to generate or find invoice PDF');

    } catch (Exception $e) {
        http_response_code(500);
        die('Error downloading invoice');
    }
});

<?php
// ============================================================================
// FILE: app/routes/umrah/bookingRoutes.php
// UMRAH post-booking routes (v2). These three handlers are the LIVE endpoints the
// v2 invoice page (app/views/modules/umrah/invoice/index.php) calls:
//   GET  /api/umrah/booking/download-invoice/{invoiceId}
//   POST /api/umrah/booking/resend-invoice
//   POST /api/umrah/booking/request-cancellation
//
// v1→v2 cleanup: the legacy submit chain that used to live here — the
// umrahLegacyServerPrice()/umrahLegacyPromoDiscount() helpers, GET
// /umrah/booking/{16hex}, POST /api/umrah/booking/save-draft and POST
// /api/umrah/booking/submit — was retired. It priced from the legacy `umrah`
// product table and was reached only by the orphaned legacy booking view. New
// bookings go through the v2 flow (umrahV2Routes.php + api/umrah/v1Routes.php:
// /api/v1/umrah/* quotes → holds → bookings/checkout). The three handlers below
// operate on the generic `bookings` row (v2-written) and are owner-guarded.
// ============================================================================
@$SECURE or die('Access Denied!');

// ============================================================================
// GET: Download invoice PDF
// GET /api/umrah/booking/download-invoice/{invoiceId}
// ============================================================================
$router->get('/api/umrah/booking/download-invoice/([A-Z0-9]{8})', function ($invoiceId) use ($SECURE, $db) {
    try {
        // Check if it's an umrah booking (include user_id for the IDOR check).
        $booking = $db->get('bookings', ['invoice_id', 'module_type', 'user_id'], ['invoice_id' => $invoiceId]);

        if (!$booking) {
            http_response_code(404);
            die('Invoice not found');
        }

        // SECURITY (IDOR): only owner / admin / creating session may download.
        // enforceInvoiceAccess() emits a 403 JSON response and exits on denial
        // for /api/ paths (this route is under /api/).
        enforceInvoiceAccess($db, $booking);

        // Always generate/refresh PDF before download
        $pdfPath = GENERATE_BOOKING_PDF($invoiceId);

        if ($pdfPath && file_exists($pdfPath)) {
            // Serve PDF
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="umrah_invoice_' . $invoiceId . '.pdf"');
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

// ============================================================================
// POST: Resend invoice email
// POST /api/umrah/booking/resend-invoice
// ============================================================================
$router->post('/api/umrah/booking/resend-invoice', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['invoice_id'])) {
            throw new Exception('Invoice ID is required');
        }

        // VALIDATE CSRF TOKEN (state-changing POST that sends email).
        $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!CSRF::validateToken($csrfToken)) {
            throw new Exception('Invalid security token');
        }

        $invoiceId = $input['invoice_id'];

        // Fetch booking details
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);

        if (!$booking) {
            throw new Exception('Booking not found');
        }

        // SECURITY (IDOR): only owner / admin / creating session may resend.
        enforceInvoiceAccess($db, $booking);

        // Fetch payment gateway name
        $paymentGatewayName = $booking['payment_gateway'];
        if (!empty($booking['payment_gateway']) && is_numeric($booking['payment_gateway'])) {
            $gateway = $db->get('payment_gateways', 'name', ['id' => $booking['payment_gateway']]);
            if ($gateway) {
                $paymentGatewayName = $gateway;
            }
        }

        // Get customer email
        $customerEmail = $booking['email'];
        $customerName = $booking['first_name'] . ' ' . $booking['last_name'];

        // Generate or get existing PDF
        $pdfPath = GENERATE_BOOKING_PDF($invoiceId, 'ticket');

        // Fetch booking data for additional info if needed
        $bookingData = json_decode($booking['booking_data'], true);

        // Send consolidated notification (Customer only).
        $notificationData = array_merge(is_array($bookingData) ? $bookingData : [], [
            'invoice_id' => $invoiceId,
            'amount' => $booking['price_markup'],
            'currency' => $booking['currency_markup'],
            'payment_status' => $booking['payment_status']
        ]);

        // Prevent partial email-template HTML from corrupting the JSON response.
        $outputBufferLevel = ob_get_level();
        ob_start();
        try {
            NOTIFY::resend('umrah', [
                'email' => $booking['email'],
                'phone' => $booking['phone'],
                'first_name' => $booking['first_name'],
                'last_name' => $booking['last_name'],
                'country_code' => $booking['phone_country_code']
            ], $notificationData, $pdfPath);
        } finally {
            while (ob_get_level() > $outputBufferLevel) {
                ob_end_clean();
            }
        }

        echo json_encode([
            'status' => true,
            'message' => 'Invoice has been resent successfully to ' . $booking['email']
        ]);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'status' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
});

// ============================================================================
// POST: Request cancellation
// POST /api/umrah/booking/request-cancellation
// ============================================================================
$router->post('/api/umrah/booking/request-cancellation', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) { $input = $_POST; }

        // SECURITY (audit H): this is a state-changing POST. Require a valid CSRF
        // token (body csrf_token or X-CSRF-TOKEN header) — the route previously
        // accepted any invoice_id with no token and no ownership check, so anyone
        // could flag any booking for cancellation (CSRF + IDOR).
        $csrf = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!class_exists('CSRF') || !CSRF::validateToken((string) $csrf)) {
            http_response_code(403);
            ob_clean();
            echo json_encode(['status' => false, 'message' => 'Invalid or missing security token']);
            exit;
        }

        if (empty($input['invoice_id'])) {
            throw new Exception('Invoice ID is required');
        }

        $invoiceId = $input['invoice_id'];

        // Check if booking exists
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);

        if (!$booking) {
            throw new Exception('Booking not found');
        }

        // SECURITY (audit H — IDOR): only the booking owner, an admin, or the
        // guest/session that created it may request cancellation.
        if (!enforceInvoiceAccess($db, $booking)) {
            http_response_code(403);
            ob_clean();
            echo json_encode(['status' => false, 'message' => 'You are not authorized to modify this booking']);
            exit;
        }

        if ($booking['cancellation_request'] == 1) {
            throw new Exception('Cancellation already requested');
        }

        // Update cancellation request
        $result = $db->update('bookings', [
            'cancellation_request' => 1
        ], [
            'invoice_id' => $invoiceId
        ]);

        if ($result) {
            // Send notification using NOTIFY library
            NOTIFY::cancellation('umrah', [
                'email' => $booking['email'] ?? '',
                'phone' => $booking['phone'] ?? '',
                'first_name' => $booking['first_name'] ?? '',
                'last_name' => $booking['last_name'] ?? '',
                'country_code' => $booking['phone_country_code'] ?? ''
            ], [
                'invoice_id' => $invoiceId,
                'amount' => $booking['price_markup'] ?? 0,
                'currency' => $booking['currency_markup'] ?? 'USD',
                'module_type' => 'Umrah'
            ]);

            ob_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Umrah cancellation request submitted successfully'
            ]);
        } else {
            throw new Exception('Failed to update cancellation request');
        }

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
});

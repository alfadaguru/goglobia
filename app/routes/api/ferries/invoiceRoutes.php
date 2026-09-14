<?php
// ============================================================================
// FERRIES API — INVOICE ROUTE
// ============================================================================
// GET /api/ferries/invoice/{invoice_id}
// Returns booking + payment details as JSON for mobile / SPA clients.
// ============================================================================

@$SECURE or die('Access Denied!');

require_once 'app/lib/jwt.php';

$router->get('/api/ferries/invoice/([a-zA-Z0-9]+)', function ($invoiceId) use ($SECURE, $db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $booking = $db->get('bookings', '*', [
            'invoice_id'  => $invoiceId,
            'module_type' => 'ferries',
        ]);

        if (!$booking) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Invoice not found.']);
            return;
        }

        // IDOR GUARD: this returns the full booking row (customer PII, pricing,
        // travellers). Restrict to admin / owner / creating session / valid
        // payment token. enforceInvoiceAccess() emits 403 JSON and exits on an
        // /api/ route for a non-owner. Was previously unauthenticated.
        if (function_exists('enforceInvoiceAccess')) {
            enforceInvoiceAccess($db, $booking);
        }

        // DECODE STORED JSON FIELDS
        $booking['booking_data']   = json_decode($booking['booking_data']   ?? '{}', true);
        $booking['booking_response'] = json_decode($booking['booking_response'] ?? '{}', true);
        $booking['travellers']     = json_decode($booking['travellers']     ?? '[]', true);

        echo json_encode(['success' => true, 'data' => $booking], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
});

// ============================================================================
// GET /api/ferries/booking/download-invoice/{invoiceId}
// ============================================================================
$router->get('/api/ferries/booking/download-invoice/([a-zA-Z0-9]+)', function ($invoiceId) use ($SECURE, $db) {
    try {
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'ferries']);
        if (!$booking) { http_response_code(404); die('Booking not found'); }

        // IDOR GUARD: the PDF contains customer PII + pricing. Restrict to
        // admin / owner / creating session / valid payment token. Was
        // previously unauthenticated. enforceInvoiceAccess() exits on denial.
        if (function_exists('enforceInvoiceAccess')) {
            enforceInvoiceAccess($db, $booking);
        }

        $pdfPath = GENERATE_BOOKING_PDF($invoiceId);
        if ($pdfPath && file_exists($pdfPath)) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="ferry_invoice_' . $invoiceId . '.pdf"');
            header('Content-Length: ' . filesize($pdfPath));
            header('Cache-Control: private, max-age=0, must-revalidate');
            readfile($pdfPath);
            exit;
        }
        throw new Exception('Failed to generate PDF');
    } catch (Exception $e) {
        http_response_code(500);
        die('Error generating invoice');
    }
});

// ============================================================================
// POST /api/ferries/booking/request-cancellation
// Client-side only flags the booking — admin performs the actual cancellation.
// ============================================================================
$router->post('/api/ferries/booking/request-cancellation', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    try {
        $input     = json_decode(file_get_contents('php://input'), true) ?: [];
        $invoiceId = trim($input['invoice_id'] ?? '');
        if (!$invoiceId) throw new Exception('Invoice ID is required');

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'ferries']);
        if (!$booking) throw new Exception('Booking not found');

        // OWNERSHIP GUARD: only the invoice owner / creating session / admin may
        // request cancellation. Was unauthenticated. enforceInvoiceAccess
        // auto-responds 403 JSON on an /api/ route and exits for a non-owner.
        if (function_exists('enforceInvoiceAccess')) {
            enforceInvoiceAccess($db, $booking);
        }

        if ($booking['booking_status'] === 'cancelled') throw new Exception('Booking is already cancelled');
        if ($booking['cancellation_request'] == 1) throw new Exception('Cancellation request already submitted');

        $db->update('bookings', ['cancellation_request' => 1], ['invoice_id' => $invoiceId]);

        NOTIFY::cancellation('ferries', [
            'email'      => $booking['email']             ?? '',
            'phone'      => $booking['phone']             ?? '',
            'first_name' => $booking['first_name']        ?? '',
            'last_name'  => $booking['last_name']         ?? '',
            'country_code' => $booking['phone_country_code'] ?? '',
        ], [
            'invoice_id'  => $invoiceId,
            'amount'      => $booking['price_markup']     ?? 0,
            'currency'    => $booking['currency_markup']  ?? 'USD',
            'module_type' => 'Ferry',
        ]);

        ob_clean();
        echo json_encode(['success' => true, 'message' => 'Cancellation request submitted successfully']);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});

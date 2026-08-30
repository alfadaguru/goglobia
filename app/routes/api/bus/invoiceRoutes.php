<?php
// ============================================================================
// BUS API — INVOICE ROUTE
// ============================================================================
// GET /api/bus/invoice/{invoice_id}
// Returns booking + payment details as JSON for mobile / SPA clients.
// (Download-PDF and request-cancellation already exist as JSON endpoints in
// app/routes/bus/bookingRoutes.php — /api/bus/booking/download-invoice/{id}
// and /api/bus/booking/request-cancellation.)
// ============================================================================

@$SECURE or die('Access Denied!');

$router->get('/api/bus/invoice/([a-zA-Z0-9]+)', function ($invoiceId) use ($SECURE, $db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $booking = $db->get('bookings', '*', [
            'invoice_id'  => $invoiceId,
            'module_type' => 'bus',
        ]);

        if (!$booking) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Invoice not found.']);
            return;
        }

        // DECODE STORED JSON FIELDS
        $booking['booking_data'] = json_decode($booking['booking_data'] ?? '{}', true);
        $booking['travellers']   = json_decode($booking['travellers']   ?? '[]', true);

        echo json_encode(['success' => true, 'data' => $booking], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
});

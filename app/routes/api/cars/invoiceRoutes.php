<?php
// FILE: app/routes/api/cars/invoice.php
// Cars API invoice endpoint

@$SECURE or die('Access Denied!');

// ====================================
// GET INVOICE API
// ====================================

$router->get('/api/cars/invoice/([a-zA-Z0-9]+)', function ($invoiceId) use ($SECURE, $db) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $booking = $db->get('bookings', '*', [
            'invoice_id'  => $invoiceId,
            'module_type' => 'cars',
        ]);

        if (!$booking) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Invoice not found.']);
            return;
        }

        // Match the Bus invoice response: return the complete booking record
        // with stored JSON fields decoded into usable objects/arrays.
        $booking['booking_data'] = json_decode($booking['booking_data'] ?? '{}', true);
        $booking['travellers']   = json_decode($booking['travellers'] ?? '[]', true);

        echo json_encode(
            ['success' => true, 'data' => $booking],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
});

/*
|--------------------------------------------------------------------------
| DOWNLOAD INVOICE PDF
| GET /api/cars/booking/download-invoice/{invoiceId}
|--------------------------------------------------------------------------
*/
$router->get('/api/cars/booking/download-invoice/([A-Z0-9]{8})', function ($invoiceId) use ($db) {
    try {
        $booking = $db->get('bookings', ['id'], [
            'invoice_id' => $invoiceId,
            'module_type' => 'cars'
        ]);

        if (!$booking) {
            http_response_code(404);
            die('Booking not found');
        }

        // Generate the latest invoice instead of requiring a pre-generated PDF.
        $pdfPath = GENERATE_BOOKING_PDF($invoiceId);

        if (!$pdfPath || !file_exists($pdfPath)) {
            throw new RuntimeException('Failed to generate invoice PDF');
        }

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="car_invoice_' . $invoiceId . '.pdf"');
        header('Content-Length: ' . filesize($pdfPath));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        readfile($pdfPath);
        exit;
    } catch (Throwable $e) {
        error_log('CAR INVOICE DOWNLOAD ERROR: ' . $e->getMessage());
        http_response_code(500);
        die('Error downloading invoice');
    }
});

/*
|--------------------------------------------------------------------------
| REQUEST CANCELLATION
| POST /api/cars/booking/request-cancellation
|--------------------------------------------------------------------------
*/
$router->post('/api/cars/booking/request-cancellation', function () use ($db) {

    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['invoice_id'])) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Invoice ID required']);
        exit;
    }

    $db->update('bookings', [
        'cancellation_request' => 1
    ], [
        'invoice_id' => $input['invoice_id']
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Cancellation request submitted'
    ]);
    exit;
});

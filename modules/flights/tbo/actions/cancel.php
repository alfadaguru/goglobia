<?php
// path: modules/flights/tbo/actions/cancel.php
// TBO Air cancel action — TBO cancellations/changes are processed through
// TBO's change-request desk, so this records the request on the booking for
// the operations team instead of pretending an API cancellation happened.
// POST flights/tbo/cancel

@$SECURE or die('Access Denied!');

global $router;

$router->post('flights/tbo/cancel', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $invoiceId = trim($_POST['invoice_id'] ?? '');
        if (empty($invoiceId)) {
            echo json_encode(['status' => false, 'message' => 'Invoice ID required']);
            exit;
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'Booking not found']);
            exit;
        }

        if ($booking['booking_status'] === 'cancelled') {
            echo json_encode(['status' => false, 'message' => 'Booking is already cancelled']);
            exit;
        }

        $bookingData = json_decode($booking['booking_data'] ?? '{}', true) ?? [];
        $bookingData['cancellation_requested_at'] = date('Y-m-d H:i:s');

        $db->update('bookings', [
            'cancellation_request' => 1,
            'booking_data'         => json_encode($bookingData),
        ], ['invoice_id' => $invoiceId]);

        echo json_encode([
            'status'  => true,
            'message' => 'Cancellation request recorded. TBO bookings are cancelled through the TBO change-request desk — the operations team will process it with the supplier.',
            'data'    => [
                'invoice_id'        => $invoiceId,
                'pnr'               => $booking['pnr'] ?? '',
                'manual_processing' => true,
            ],
        ]);

    } catch (Throwable $e) {
        error_log('TBO CANCEL ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred. Please contact support.']);
    }
});

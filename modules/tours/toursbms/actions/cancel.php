<?php
// ============================================================================
// ToursBMS — CANCEL BOOKING (LOCAL). Marks the booking cancelled.
// POST /modules/tours/toursbms/cancel
// ============================================================================
@$SECURE or die('Access Denied!');

$router->post('tours/toursbms/cancel', function () use ($db) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    if (empty($_SESSION['admin_logged_in']) && strtolower($_SESSION['user_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['status' => false, 'success' => false, 'message' => 'Unauthorized']); exit;
    }

    try {
        $invoiceId = trim((string) ($_POST['invoice_id'] ?? ''));
        if ($invoiceId === '') throw new Exception('Invoice ID is required');

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'tours']);
        if (!$booking) throw new Exception('Tours booking not found');
        if (strtolower((string) $booking['booking_status']) === 'cancelled') {
            throw new Exception('Booking is already cancelled');
        }

        $db->update('bookings', [
            'booking_status' => 'cancelled',
            'cancellation_status' => 1,
            'cancellation_request' => 1,
            'cancellation_response' => 'Cancelled by admin on ' . date('Y-m-d H:i:s'),
        ], ['invoice_id' => $invoiceId]);

        echo json_encode(['status' => true, 'success' => true, 'message' => 'Booking cancelled successfully.']);
        exit;
    } catch (\Throwable $e) {
        echo json_encode(['status' => false, 'success' => false,
            'message' => $e->getMessage(), 'response_error' => $e->getMessage()]);
        exit;
    }
});

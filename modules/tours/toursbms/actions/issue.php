<?php
// ============================================================================
// ToursBMS — ISSUE BOOKING (LOCAL). No supplier booking API exists, so this
// confirms the booking locally and generates a PNR.
// POST /modules/tours/toursbms/issue
// ============================================================================
@$SECURE or die('Access Denied!');

$router->post('tours/toursbms/issue', function () use ($db) {
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
        if (in_array(strtolower((string) $booking['booking_status']), ['cancelled', 'voided'], true)) {
            throw new Exception('Cannot issue a ' . $booking['booking_status'] . ' booking');
        }

        $pnr = trim((string) ($booking['pnr'] ?? ''));
        if ($pnr === '' || strtolower($pnr) === 'not issued') {
            $pnr = 'PNR' . strtoupper(substr(md5($invoiceId . microtime()), 0, 6));
        }
        $db->update('bookings', ['pnr' => $pnr, 'booking_status' => 'confirmed', 'error_response' => null],
            ['invoice_id' => $invoiceId]);

        echo json_encode(['status' => true, 'success' => true,
            'message' => 'Booking issued successfully. PNR: ' . $pnr, 'pnr' => $pnr,
            'booking_status' => 'confirmed']);
        exit;
    } catch (\Throwable $e) {
        echo json_encode(['status' => false, 'success' => false,
            'message' => $e->getMessage(), 'response_error' => $e->getMessage()]);
        exit;
    }
});

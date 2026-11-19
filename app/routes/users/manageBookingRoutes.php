<?php
// FILE: app/routes/users/manageBookingRoutes.php
// Customer self-service "Manage my booking" — a single, module-agnostic
// cancellation-REQUEST endpoint the bookings list / invoice can call for any
// service. It mirrors every per-service request-cancellation handler
// (sets cancellation_request=1 + reason + timestamp); it NEVER self-refunds —
// the actual refund stays staff-decided via the admin refund route (money-safe).
// Ownership is enforced so a stranger cannot flag someone else's booking.

@$SECURE or die('Access Denied!');

$router->post('/booking/request-cancellation', function () use ($SECURE, $db) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    try {
        // Login required (this is the customer surface).
        if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Login required']);
            exit(0);
        }

        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input)) { $input = $_POST; }

        // CSRF (JSON body or X-CSRF-TOKEN header).
        $csrf = (string) ($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!class_exists('CSRF') || !CSRF::validateToken($csrf)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid security token']);
            exit(0);
        }

        $invoiceId = trim((string) ($input['invoice_id'] ?? ''));
        if ($invoiceId === '') { throw new Exception('Invoice ID required'); }
        $reason = trim((string) ($input['reason'] ?? ''));
        if (mb_strlen($reason) > 500) { $reason = mb_substr($reason, 0, 500); }

        // Look up by invoice only (module-agnostic), then ownership-gate.
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) { throw new Exception('Booking not found'); }

        // OWNERSHIP: only the invoice owner / creating session / admin.
        // enforceInvoiceAccess self-responds 403 JSON on an /api/-style call for a non-owner.
        if (function_exists('enforceInvoiceAccess') && !enforceInvoiceAccess($db, $booking, null)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Not authorised for this booking.']);
            exit(0);
        }

        $status = strtolower((string) ($booking['booking_status'] ?? ''));

        // Terminal states — nothing to request.
        if (in_array($status, ['cancelled', 'voided', 'refunded'], true)) {
            echo json_encode(['success' => false, 'message' => 'This booking is already cancelled.']);
            exit(0);
        }
        // Already requested — idempotent, report the existing state rather than re-flag.
        if ((int) ($booking['cancellation_request'] ?? 0) === 1) {
            echo json_encode(['success' => true, 'already' => true, 'message' => 'Your cancellation request is already being reviewed.']);
            exit(0);
        }

        // Record the request (same fields every per-service handler uses). The refund
        // itself is processed by staff via the admin refund route — never here.
        $update = [
            'cancellation_request' => 1,
            'cancellation_status'  => 0,
            'refund_requested_at'  => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ];
        // Store the customer reason where the schema has room for it.
        if ($reason !== '' && array_key_exists('refund_reason', $booking)) {
            $update['refund_reason'] = $reason;
        }
        $db->update('bookings', $update, ['invoice_id' => $invoiceId]);

        // Best-effort: notify ops of the request (never fatal the response).
        try {
            if (function_exists('triggerWebhook')) {
                triggerWebhook($db, 'booking', 'cancellation_requested', [
                    'invoice_id' => $invoiceId,
                    'module'     => (string) ($booking['module_type'] ?? $booking['module'] ?? ''),
                    'reason'     => $reason,
                ]);
            }
        } catch (\Throwable $e) { error_log('MANAGE_BOOKING_NOTIFY: ' . $e->getMessage()); }

        echo json_encode([
            'success' => true,
            'message' => 'Your cancellation request has been submitted. Our team will review it and process any eligible refund.',
        ]);
        exit(0);
    } catch (\Throwable $e) {
        error_log('MANAGE_BOOKING_REQUEST_CANCELLATION: ' . $e->getMessage());
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Could not submit your cancellation request. Please try again.']);
        exit(0);
    }
});

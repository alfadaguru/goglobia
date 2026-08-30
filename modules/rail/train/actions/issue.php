<?php
/**
 * TRAIN (RAIL) BOOKING ISSUE HANDLER
 * =====================================
 * Places the train order with the supplier API (POST /ticket/order).
 *
 * Registered on the Modules API Gateway ($router) via modules/rail/train/index.php's
 * `include "actions/issue.php";`. Reached at POST /modules/rail/train/issue — the URL
 * used by both app/lib/payment-gateway.php (auto-issue after successful payment) and
 * the admin "Issue Booking" button (app/views/admin/bookings/edit.php).
 */

$router->post('/rail/train/issue', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $invoiceId = (string)($_POST['invoice_id'] ?? '');
        echo json_encode(_train_issue_booking($db, $invoiceId));
    } catch (Throwable $e) {
        echo json_encode(['status' => false, 'message' => $e->getMessage()]);
    }
});

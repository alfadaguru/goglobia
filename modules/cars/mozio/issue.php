<?php
// modules/cars/mozio/issue.php
// MOZIO TRANSFER BOOKING ISSUE ENDPOINT
// Called by payment-gateway.php via HTTP POST after successful payment
//
// Mozio booking flow (docs):
//   1. POST /v2/reservations/
//   2a. Partner-managed payment → 201 pending → GET /v2/reservations/{search_id}/poll/
//   2b. Mozio Stripe checkout   → 202 redirect → customer pays → poll on invoice

@$SECURE or die('Access Denied!');

require_once __DIR__ . '/lib.php';

if (!function_exists('logApiCall')) {
    require_once __DIR__ . '/../../helpers.php';
}

$router->post('cars/mozio/issue', function () use ($db) {
    header('Content-Type: application/json');

    while (ob_get_level()) {
        ob_end_clean();
    }

    try {
        $invoiceId = trim((string)($_POST['invoice_id'] ?? ''));
        echo json_encode(mozioIssueReservation($db, $invoiceId));
    } catch (Exception $e) {
        error_log('MOZIO ISSUE ERROR: ' . $e->getMessage());
        echo json_encode([
            'status'         => false,
            'message'        => 'Exception: ' . $e->getMessage(),
            'response_error' => $e->getMessage(),
        ]);
    }
});

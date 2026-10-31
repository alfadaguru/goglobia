<?php
// TRAVELSURE cancel — POST modules/insurance/travelsure/cancel
// Cancel a policy WITHOUT a money refund (status flip only), mirroring the other
// suppliers' cancel.php. STUB: no provider → records the cancellation locally and
// flags for manual provider cancellation.
@$SECURE or die('Access Denied!');

global $router;

$router->post('insurance/travelsure/cancel', function () use ($db) {
    header('Content-Type: application/json; charset=utf-8');
    while (ob_get_level()) { ob_end_clean(); }

    try {
        $invoiceId = trim((string) ($_POST['invoice_id'] ?? ''));
        if ($invoiceId === '') { throw new Exception('Invoice ID is required'); }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'insurance']);
        if (!$booking) { throw new Exception('Insurance booking not found'); }

        $cfg = _travelsure_cfg($db);
        if (function_exists('travelsure_is_configured') && travelsure_is_configured($cfg)) {
            // LIVE: _travelsure_request($db,'POST','/policy/cancel',['json'=>['policy'=>$booking['pnr']]]);
        }

        $db->update('bookings', [
            'booking_status' => 'cancelled',
            'updated_at'     => date('Y-m-d H:i:s'),
        ], ['invoice_id' => $invoiceId]);

        echo json_encode([
            'status'  => true,
            'message' => 'Policy marked cancelled. If a policy was issued with a provider, cancel it there too.',
        ], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['status' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
    }
    exit;
});

<?php
// ============================================================================
// PKFARE FLIGHT CANCEL
// ============================================================================
// ENDPOINT: POST /flights/pkfare/cancel
//
// IMPORTANT (why this file was rewritten):
//   This file previously contained a COPY of search.php and registered a
//   duplicate `flights/pkfare/search` route (a collision), and it was not even
//   included by index.php — so PKFare had no working `/cancel` route.
//
//   It now performs a real cancellation via the PKFare OrderCancel API
//   (same endpoint/auth as actions/void.php: sign = md5(partnerId . apiKey),
//   POST https://api.pkfare.com/air/api/OrderCancel). Self-contained so it does
//   not depend on — or risk breaking — the existing void.php handler.
// ============================================================================

$router->post('flights/pkfare/cancel', function () use ($db) {

    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);

    $invoice_id = '';

    try {
        if (!$db) {
            throw new Exception('Database connection not available');
        }

        $invoice_id = $_POST['invoice_id'] ?? '';
        if (empty($invoice_id)) {
            throw new Exception('Missing invoice_id parameter in POST data');
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
        if (!$booking) {
            throw new Exception('Booking not found for invoice_id: ' . $invoice_id);
        }

        // Already cancelled → idempotent success.
        if (in_array($booking['booking_status'], ['cancelled', 'voided'], true)) {
            ob_clean();
            echo json_encode([
                'status'         => true,
                'message'        => 'Booking already cancelled',
                'invoice_id'     => $invoice_id,
                'booking_status' => $booking['booking_status'],
            ], JSON_UNESCAPED_SLASHES);
            return;
        }

        // Credentials.
        $moduleData = $db->get('modules', '*', [
            'name' => $booking['module'] ?? 'pkfare',
            'type' => $booking['module_type'] ?? 'flights',
        ]);
        if (!$moduleData) {
            throw new Exception('Module configuration not found');
        }
        $partnerId = $moduleData['c1'] ?? '';
        $apiKey    = $moduleData['c2'] ?? '';
        if (empty($partnerId) || empty($apiKey)) {
            throw new Exception('PKFare API credentials not configured');
        }
        $signature = md5($partnerId . $apiKey);

        // Resolve the PKFare order number (from booking_response, else PNR).
        $orderNo = null;
        $resp = json_decode((string) ($booking['booking_response'] ?? ''), true);
        if (is_array($resp)) {
            $orderNo = $resp['orderNo'] ?? $resp['data']['orderNo'] ?? $resp['order']['orderNo'] ?? null;
        }
        if (empty($orderNo)) {
            $orderNo = $booking['pnr'] ?? null;
        }

        // No supplier order → cancel locally (never issued at the airline).
        if (empty($orderNo)) {
            $db->update('bookings', ['booking_status' => 'cancelled'], ['invoice_id' => $invoice_id]);
            ob_clean();
            echo json_encode([
                'status'     => true,
                'message'    => 'Booking cancelled locally — no PKFare order number found (was not confirmed with the airline).',
                'invoice_id' => $invoice_id,
            ], JSON_UNESCAPED_SLASHES);
            return;
        }

        // Real OrderCancel call.
        $payload = json_encode([
            'authentication' => ['partnerId' => $partnerId, 'sign' => $signature],
            'orderNo'        => $orderNo,
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://api.pkfare.com/air/api/OrderCancel',
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Content-Length: ' . strlen($payload)],
        ]);
        $response  = curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $rd = json_decode((string) $response, true);
        $isSuccess = ($httpCode === 200) && (
            (isset($rd['status']) && $rd['status'] === true) ||
            (isset($rd['success']) && $rd['success'] === true) ||
            (isset($rd['data']['status']) && $rd['data']['status'] === 'success')
        );

        if ($isSuccess) {
            $db->update('bookings', [
                'booking_status'        => 'cancelled',
                'cancellation_response' => $response,
                'error_response'        => null,
            ], ['invoice_id' => $invoice_id]);
            ob_clean();
            echo json_encode([
                'status'     => true,
                'message'    => 'Flight booking cancelled successfully with PKFare',
                'invoice_id' => $invoice_id,
                'order_no'   => $orderNo,
            ], JSON_UNESCAPED_SLASHES);
            return;
        }

        // Failure. Treat "not found / already cancelled" as a local cancel.
        $errorMessage = $rd['message'] ?? $rd['error'] ?? $rd['data']['message'] ??
            ($curlError ? "Connection Error: $curlError" : "HTTP $httpCode: " . substr((string) $response, 0, 200));

        if (stripos($errorMessage, 'not found') !== false ||
            stripos($errorMessage, 'already cancelled') !== false ||
            stripos($errorMessage, 'does not exist') !== false) {
            $db->update('bookings', [
                'booking_status' => 'cancelled',
                'error_response' => json_encode(['note' => 'Order not found at PKFare — cancelled locally', 'original_error' => $errorMessage]),
            ], ['invoice_id' => $invoice_id]);
            ob_clean();
            echo json_encode(['status' => true, 'message' => 'Booking cancelled locally. Order was not found in the airline system.', 'invoice_id' => $invoice_id], JSON_UNESCAPED_SLASHES);
            return;
        }

        $db->update('bookings', [
            'error_response' => json_encode(['error' => 'Cancellation failed', 'message' => $errorMessage, 'http_code' => $httpCode]),
        ], ['invoice_id' => $invoice_id]);
        ob_clean();
        echo json_encode(['status' => false, 'message' => 'PKFare cancellation failed: ' . $errorMessage, 'invoice_id' => $invoice_id], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        error_log('PKFARE CANCEL ERROR (' . $invoice_id . '): ' . $e->getMessage());
        ob_clean();
        echo json_encode(['status' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
    }
});

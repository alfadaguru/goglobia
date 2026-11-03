<?php
// path: modules/flights/tiqwa/actions/void.php
// VOID ACTION — Tiqwa Flights.
// Tiqwa has NO void endpoint (voiding = cancelling a just-issued ticket within the
// airline void window; Tiqwa's API only exposes cancel: POST /{booking_reference}).
// The route exists so the central action set is complete and the dispatcher never
// hits a missing endpoint; it delegates to cancel semantics and reports clearly.
@$SECURE or die('Access Denied!');

$router->post('flights/tiqwa/void', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $invoice_id = $_POST['invoice_id'] ?? '';
        if (empty($invoice_id)) {
            echo json_encode(['status' => false, 'message' => 'Invoice ID required']);
            exit;
        }
        if (function_exists('supplier_action_guard') && !supplier_action_guard($invoice_id)) { return; }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'Booking not found']);
            exit;
        }

        $credentials = getTiqwaCredentials($db);
        if (!$credentials || $credentials['api_token'] === '') {
            echo json_encode(['status' => false, 'message' => 'Tiqwa credentials not configured']);
            exit;
        }
        $token = $credentials['api_token'];
        $base  = $credentials['flight_base'];

        $booking_data = json_decode((string) $booking['booking_data'], true);
        if (!is_array($booking_data)) $booking_data = [];
        $reference = trim((string) ($booking_data['supplier_ref'] ?? $booking_data['tiqwa_reference'] ?? ''));
        if ($reference === '') {
            echo json_encode(['status' => false, 'message' => 'Tiqwa has no void endpoint and no booking reference was found.']);
            exit;
        }

        // Tiqwa has no distinct void — use cancel (POST /{booking_reference}).
        $res = tiqwaApiRequest('POST', $base . '/' . rawurlencode($reference), [], $token, [], 90);
        tiqwaLog('void/cancel', ['reference' => $reference], $res['raw'], $res['http_code'], $res['curl_error'], $invoice_id);
        $data = is_array($res['data']) ? $res['data'] : [];
        $ok = ($res['success'] && (!empty($data['success']) || strtolower((string)($data['status'] ?? '')) === 'cancelled' || !empty($data['message'])))
            || ($res['http_code'] === 409);

        if (!$ok) {
            echo json_encode(['status' => false, 'message' => 'Tiqwa void (via cancel) failed: ' . tiqwaExtractError($res), 'http' => $res['http_code']]);
            exit;
        }

        $booking_data['tiqwa_cancel'] = ['reference' => $reference, 'status' => 'cancelled', 'cancelled_at' => date('c'), 'via' => 'void'];
        $db->update('bookings', [
            'booking_status'        => 'cancelled',
            'cancellation_status'   => 1,
            'cancellation_response' => json_encode($booking_data['tiqwa_cancel']),
            'booking_data'          => json_encode($booking_data),
        ], ['invoice_id' => $invoice_id]);

        echo json_encode([
            'status'    => true,
            'message'   => 'Tiqwa has no separate void; the booking was cancelled instead. Process any customer refund on the platform.',
            'reference' => $reference,
        ]);
        exit;

    } catch (Throwable $e) {
        error_log('TIQWA VOID ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
        exit;
    }
});

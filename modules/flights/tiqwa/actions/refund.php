<?php
// path: modules/flights/tiqwa/actions/refund.php
// REFUND ACTION — Tiqwa Flights.
// Tiqwa exposes NO refund endpoint (docs/research/tiqwa/Flight.v1.json has only a
// cancel: POST /{booking_reference}). A cancelled Tiqwa booking cannot be
// reissued, and any supplier-side refund is handled by Tiqwa out-of-band (wallet /
// support). So the supplier-side "refund" here is a CANCEL, and the CUSTOMER
// refund on our own gateway is the platform's separate refund path. This endpoint
// therefore (a) cancels at Tiqwa if not already cancelled, and (b) reports that the
// customer refund must be processed on the platform — it never fabricates a
// supplier refund Tiqwa does not provide.
@$SECURE or die('Access Denied!');

$router->post('flights/tiqwa/refund', function () use ($db) {
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

        $alreadyCancelled = strtolower((string) ($booking['booking_status'] ?? '')) === 'cancelled';

        // Cancel at Tiqwa first (the only supplier-side "undo" available), unless done.
        if (!$alreadyCancelled && $reference !== '') {
            $res = tiqwaApiRequest('POST', $base . '/' . rawurlencode($reference), [], $token, [], 90);
            tiqwaLog('refund/cancel', ['reference' => $reference], $res['raw'], $res['http_code'], $res['curl_error'], $invoice_id);
            $data = is_array($res['data']) ? $res['data'] : [];
            $ok = ($res['success'] && (!empty($data['success']) || strtolower((string)($data['status'] ?? '')) === 'cancelled' || !empty($data['message'])))
                || ($res['http_code'] === 409);
            if ($ok) {
                $booking_data['tiqwa_cancel'] = ['reference' => $reference, 'status' => 'cancelled', 'cancelled_at' => date('c'), 'via' => 'refund'];
                $db->update('bookings', [
                    'booking_status'        => 'cancelled',
                    'cancellation_status'   => 1,
                    'cancellation_response' => json_encode($booking_data['tiqwa_cancel']),
                    'booking_data'          => json_encode($booking_data),
                ], ['invoice_id' => $invoice_id]);
                $alreadyCancelled = true;
            } else {
                echo json_encode(['status' => false, 'message' => 'Could not cancel at Tiqwa before refund: ' . tiqwaExtractError($res), 'http' => $res['http_code']]);
                exit;
            }
        }

        echo json_encode([
            'status'        => true,
            'supplier_refund' => false,
            'message'       => 'Tiqwa has no refund API. The booking is '
                . ($alreadyCancelled ? 'cancelled' : 'not cancellable here') . ' at the supplier; '
                . 'process the CUSTOMER refund on the platform (gateway/wallet) — any Tiqwa-side refund is handled by Tiqwa manually.',
            'reference'     => $reference,
        ]);
        exit;

    } catch (Throwable $e) {
        error_log('TIQWA REFUND ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
        exit;
    }
});

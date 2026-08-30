<?php
// path: modules/flights/mystifly/actions/cancel.php
// Mystifly cancel booking action
// POST /flights/mystifly/cancel
@$SECURE or die('Access Denied!');

global $router;

$router->post('/flights/mystifly/cancel', function () use ($db) {
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

        $credentials = getMystiflyCredentials($db);
        if (!$credentials) {
            echo json_encode(['status' => false, 'message' => 'Mystifly API credentials are not configured.']);
            exit;
        }

        $target  = (strtolower($credentials['environment'] ?? 'demo') === 'live') ? 'Live' : 'Test';
        $bookingData = json_decode($booking['booking_data'] ?? '{}', true) ?? [];

        $mfReference = $bookingData['mf_reference']
            ?? $bookingData['MFRef']
            ?? $bookingData['UniqueID']
            ?? '';

        if (empty($mfReference)) {
            echo json_encode(['status' => false, 'message' => 'MF Reference not found. Cannot cancel via API.']);
            exit;
        }

        // Demo placeholder ref — skip API call, treat as successful cancellation
        if (str_starts_with($mfReference, 'PENDING-')) {
            $bookingData['cancelled_at'] = date('Y-m-d H:i:s');
            $db->update('bookings', [
                'booking_status'       => 'cancelled',
                'cancellation_status'  => 1,
                'cancellation_request' => 1,
                'booking_data'         => json_encode($bookingData),
            ], ['invoice_id' => $invoiceId]);

            echo json_encode([
                'status'  => true,
                'message' => 'Booking cancelled successfully.',
                'data'    => ['invoice_id' => $invoiceId, 'mf_reference' => $mfReference],
            ]);
            exit;
        }

        // Call Mystifly Cancel API
        // TODO: confirm exact cancel endpoint path with Mystifly docs
        $result = mystiflyApiRequest($db, 'api/v1/Booking/Cancel', [
            'UniqueID' => $mfReference,
            'Target'   => $target,
        ], 'POST', $invoiceId);

        $data       = $result['data'] ?? [];
        $statusCode = $data['Status']['Code'] ?? '';

        if ($statusCode !== '001' || !$result['success']) {
            echo json_encode([
                'status'  => false,
                'message' => $data['Status']['Message'] ?? 'Cancellation failed. Please contact support.',
                'debug'   => ['supplier_message' => $data['Status']['Message'] ?? ''],
            ]);
            exit;
        }

        $refundAmount = $data['RefundAmount'] ?? $data['Refund']['Amount'] ?? null;
        $refundCurrency = $data['Currency'] ?? null;

        // Update booking
        $bookingData['cancellation_response'] = $data;
        $bookingData['cancelled_at'] = date('Y-m-d H:i:s');

        $db->update('bookings', [
            'booking_status'        => 'cancelled',
            'cancellation_status'   => 1,
            'cancellation_request'  => 1,
            'cancellation_response' => json_encode($data),
            'booking_data'          => json_encode($bookingData),
        ], ['invoice_id' => $invoiceId]);

        echo json_encode([
            'status'  => true,
            'message' => 'Booking cancelled successfully.',
            'data'    => [
                'invoice_id'      => $invoiceId,
                'mf_reference'    => $mfReference,
                'refund_amount'   => $refundAmount,
                'currency'        => $refundCurrency,
            ],
        ]);

    } catch (Throwable $e) {
        error_log('MYSTIFLY CANCEL ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred. Please contact support.']);
    }
});

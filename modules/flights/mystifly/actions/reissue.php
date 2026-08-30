<?php
// path: modules/flights/mystifly/actions/reissue.php
// Mystifly reissue (flight change) action
// POST /flights/mystifly/reissue
@$SECURE or die('Access Denied!');

global $router;

$router->post('/flights/mystifly/reissue', function () use ($db) {
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

        $credentials = getMystiflyCredentials($db);
        if (!$credentials) {
            echo json_encode(['status' => false, 'message' => 'Mystifly API credentials are not configured.']);
            exit;
        }

        $target      = (strtolower($credentials['environment'] ?? 'demo') === 'live') ? 'Live' : 'Test';
        $bookingData = json_decode($booking['booking_data'] ?? '{}', true) ?? [];

        $mfReference = $bookingData['mf_reference']
            ?? $bookingData['MFRef']
            ?? $bookingData['UniqueID']
            ?? '';

        if (empty($mfReference)) {
            echo json_encode(['status' => false, 'message' => 'MF Reference not found. Cannot reissue via API.']);
            exit;
        }

        // Collect new itinerary data from POST if provided
        $newFareSourceCode = trim($_POST['FareSourceCode'] ?? $_POST['fare_source_code'] ?? '');

        $payload = [
            'ptrType' => 'Reissue',
            'mFRef'   => $mfReference,
            'Target'  => $target,
        ];

        // If a new FareSourceCode is provided (from a new search for the change), include it
        if (!empty($newFareSourceCode)) {
            $payload['newFareSourceCode'] = $newFareSourceCode;
        }

        // TODO: confirm exact PTR Reissue payload structure with Mystifly docs
        $result = mystiflyApiRequest($db, 'api/PostTicketingRequest', $payload, 'POST', $invoiceId);

        $data       = $result['data'] ?? [];
        $statusCode = $data['Status']['Code'] ?? '';

        if ($statusCode !== '001' || !$result['success']) {
            $bookingData['reissue_response'] = $data;
            $db->update('bookings', [
                'booking_data'   => json_encode($bookingData),
                'error_response' => json_encode($data),
            ], ['invoice_id' => $invoiceId]);

            echo json_encode([
                'status'  => false,
                'message' => $data['Status']['Message'] ?? 'Reissue could not be processed. Please contact support.',
            ]);
            exit;
        }

        $newMfReference = $data['UniqueID'] ?? $data['MFRef'] ?? $mfReference;

        $bookingData['reissue_response']       = $data;
        $bookingData['reissued_at']            = date('Y-m-d H:i:s');
        $bookingData['mf_reference_reissue']   = $newMfReference;

        $db->update('bookings', [
            'booking_data'     => json_encode($bookingData),
            'booking_response' => json_encode($data),
        ], ['invoice_id' => $invoiceId]);

        echo json_encode([
            'status'  => true,
            'message' => 'Reissue processed successfully.',
            'data'    => [
                'invoice_id'        => $invoiceId,
                'mf_reference'      => $mfReference,
                'new_mf_reference'  => $newMfReference,
            ],
        ]);

    } catch (Throwable $e) {
        error_log('MYSTIFLY REISSUE ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred. Please contact support.']);
    }
});

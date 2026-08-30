<?php
/**
 * Refresh the hotel confirmation number (HCN) through BookingDetail.
 * POST stays/tbo-holidays/hcn-refresh
 */

require_once __DIR__ . '/../api.php';

$router->post('stays/tbo-holidays/hcn-refresh', function () use ($db) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    header('Content-Type: application/json');

    try {
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            throw new Exception('Administrator authentication is required.');
        }
        $invoiceId = trim((string) ($_POST['invoice_id'] ?? ''));
        if ($invoiceId === '') {
            throw new Exception('Missing invoice_id parameter.');
        }

        $booking = $db->get('bookings', '*', [
            'invoice_id' => $invoiceId,
            'module' => 'tbo-holidays',
        ]);
        if (!$booking || empty($booking['pnr'])) {
            throw new Exception('Confirmed TBO booking not found.');
        }

        $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
        $bookingData = is_array($bookingData) ? $bookingData : [];
        if (!empty($bookingData['tbo_hcn'])) {
            echo json_encode(['success' => true, 'hcn' => $bookingData['tbo_hcn'], 'already_available' => true]);
            return;
        }

        $checkin = strtotime(tboHolidaysParseDate((string) ($bookingData['checkin'] ?? '')));
        $hoursToCheckin = $checkin ? (($checkin - time()) / 3600) : null;
        if ($hoursToCheckin === null || $hoursToCheckin <= 0 || $hoursToCheckin > 720) {
            throw new Exception('TBO provides HCN through API only when check-in is within 30 days.');
        }

        $retryCount = (int) ($bookingData['tbo_hcn_retry_count'] ?? 0);
        $isInitialCheck = empty($bookingData['tbo_hcn_initial_checked_at']);
        if (!$isInitialCheck && $retryCount >= 3) {
            throw new Exception('HCN is still unavailable after 3 retries. Raise an operations ticket with TBO.');
        }

        $nextCheckAt = strtotime((string) ($bookingData['tbo_hcn_next_check_at'] ?? ''));
        if ($nextCheckAt && $nextCheckAt > time()) {
            throw new Exception('HCN check is scheduled for ' . date('Y-m-d H:i:s', $nextCheckAt) . ' per TBO SLA.');
        }

        $module = tboHolidaysGetModule($db);
        if (!$module) {
            throw new Exception('TBO Holidays module is not configured.');
        }
        $payment = tboHolidaysPaymentConfig($module);
        $payload = [
            'ConfirmationNumber' => $booking['pnr'],
            'PaymentMode' => $payment['payment_mode'],
        ];
        $result = tboHolidaysCall($module, 'BookingDetail', $payload, 'POST', 60);
        logApiCall(
            'BookingDetailHCN',
            $payload,
            tboHolidaysSanitizeForLog($result['data'] ?? ['error' => $result['error'] ?? null]),
            (int) ($result['http_code'] ?? 0),
            __DIR__ . '/../logs',
            'booking_' . preg_replace('/[^A-Za-z0-9_-]/', '', $invoiceId)
        );

        $status = (int) ($result['status_code'] ?? 0);
        if (!$result['success'] || $status !== 200) {
            throw new Exception(tboHolidaysStatusMessage(
                $status,
                (string) ($result['error'] ?? 'BookingDetail failed.')
            ));
        }

        $hcn = tboHolidaysExtractHcn((array) ($result['data'] ?? []));
        $bookingData['tbo_booking_detail'] = $result['data'] ?? null;
        if ($hcn) {
            $bookingData['tbo_hcn'] = $hcn;
            $bookingData['tbo_hcn_received_at'] = date('Y-m-d H:i:s');
            $bookingData['tbo_hcn_next_check_at'] = null;
        } else {
            if ($isInitialCheck) {
                $bookingData['tbo_hcn_initial_checked_at'] = date('Y-m-d H:i:s');
            } else {
                $bookingData['tbo_hcn_retry_count'] = $retryCount + 1;
            }
            $bookingData['tbo_hcn_next_check_at'] = date('Y-m-d H:i:s', time() + 3600);
        }

        $db->update('bookings', [
            'booking_data' => json_encode($bookingData),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $booking['id']]);

        echo json_encode([
            'success' => true,
            'hcn' => $hcn,
            'retry_count' => (int) ($bookingData['tbo_hcn_retry_count'] ?? 0),
            'next_check_at' => $bookingData['tbo_hcn_next_check_at'] ?? null,
            'message' => $hcn ? 'Hotel confirmation number received.' : 'HCN not available yet; retry scheduled in one hour.',
        ]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
});

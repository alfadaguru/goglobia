<?php
// Route: POST ferries/kikoto/void
// A ferry booking has no separate "void" distinct from cancellation — Kikoto
// only exposes POST /bookings/{locator}/cancel. This endpoint exists for a
// uniform admin lifecycle surface and performs that same supplier cancellation.

global $router;

$router->post('ferries/kikoto/void', function () use ($db) {
    @set_time_limit(30);
    header('Content-Type: application/json; charset=utf-8');

    try {
        $invoiceId = trim((string)($_POST['invoice_id'] ?? ''));
        if ($invoiceId === '') {
            echo json_encode(['status' => false, 'message' => 'invoice_id is required']);
            return;
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'ferries']);
        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'Booking not found']);
            return;
        }

        if (in_array(($booking['booking_status'] ?? ''), ['cancelled', 'voided'], true)) {
            echo json_encode(['status' => true, 'message' => 'Booking is already cancelled/voided.']);
            return;
        }

        $cfg = _kikoto_cfg($db);
        if (empty($cfg) || ($cfg['status'] ?? '0') === '0') {
            echo json_encode(['status' => false, 'message' => 'Kikoto ferries module is not enabled']);
            return;
        }

        $bookingData = json_decode($booking['booking_data'] ?? '{}', true) ?: [];
        $locators    = $bookingData['locators'] ?? [];

        if (empty($locators) && empty($booking['pnr'])) {
            $db->update('bookings', [
                'booking_status'        => 'cancelled',
                'cancellation_status'   => 1,
                'cancellation_request'  => 1,
                'cancellation_response' => json_encode(['cancelled_at' => date('Y-m-d H:i:s'), 'note' => 'Voided before issuance']),
                'updated_at'            => date('Y-m-d H:i:s'),
            ], ['invoice_id' => $invoiceId]);
            echo json_encode(['status' => true, 'message' => 'Booking voided (was not yet issued with supplier)']);
            return;
        }

        if (empty($locators) && !empty($booking['pnr'])) {
            $locators = array_filter(array_map('trim', explode(',', $booking['pnr'])));
        }

        $cancelled = [];
        $failed    = [];
        $totalFee  = 0.0;

        foreach ($locators as $locator) {
            if (!preg_match('/^[A-Z0-9]{4,20}$/i', $locator)) {
                $failed[] = ['locator' => $locator, 'error' => 'Invalid locator format'];
                continue;
            }
            $res = _kikoto_request('POST', '/bookings/' . urlencode($locator) . '/cancel', [
                'cfg'     => $cfg,
                'timeout' => 20,
            ]);
            if ($res['ok']) {
                $totalFee += (float)($res['data']['data']['fee'] ?? 0);
                $cancelled[] = $locator;
            } else {
                $failed[] = ['locator' => $locator, 'error' => $res['error'] ?? 'Unknown error'];
            }
        }

        if (!empty($failed) && empty($cancelled)) {
            echo json_encode(['status' => false, 'message' => 'Void failed: ' . ($failed[0]['error'] ?? 'Unknown'), 'details' => $failed]);
            return;
        }

        $db->update('bookings', [
            'booking_status'        => 'cancelled',
            'cancellation_status'   => 1,
            'cancellation_request'  => 1,
            'cancellation_response' => json_encode([
                'voided_at'          => date('Y-m-d H:i:s'),
                'cancelled_locators' => $cancelled,
                'failed_locators'    => $failed,
                'cancellation_fee'   => $totalFee,
                'currency'           => $cfg['currency'] ?? 'EUR',
            ]),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['invoice_id' => $invoiceId]);

        $msg = 'Booking voided (cancelled) successfully.';
        if ($totalFee > 0) {
            $msg .= ' Cancellation fee: ' . $totalFee . ' ' . ($cfg['currency'] ?? 'EUR');
        }
        if (!empty($failed)) {
            $msg .= ' Note: ' . count($failed) . ' locator(s) could not be voided with supplier.';
        }

        echo json_encode(['status' => true, 'message' => $msg, 'cancelled' => $cancelled, 'cancellation_fee' => $totalFee]);
    } catch (Throwable $e) {
        echo json_encode(['status' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
});

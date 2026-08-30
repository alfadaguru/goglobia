<?php
/**
 * FERRIES PAYMENT WEBHOOK
 * Fires after payment gateway processes payment for a ferries booking.
 * On completed payment: calls Kikoto /bookings/{ref}/confirm, stores locators, marks booking confirmed.
 */

if ($event === 'ferries.payment.completed') {
    try {
        global $db;
        $invoiceId = $data['invoice_id'] ?? '';
        if (!$invoiceId) return ['status' => 'error', 'message' => 'No invoice_id'];

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) return ['status' => 'error', 'message' => 'Booking not found'];

        $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
        $reference   = $bookingData['reference'] ?? '';
        if (!$reference) return ['status' => 'error', 'message' => 'No Kikoto reference stored'];

        // Already issued (has PNR) — skip
        if (!empty($booking['pnr'])) {
            return ['status' => 'success', 'message' => 'Already issued'];
        }

        // Load Kikoto helpers
        if (!function_exists('_kikoto_cfg')) {
            require_once dirname(__DIR__, 3) . '/modules/ferries/kikoto/api.php';
        }
        $cfg = _kikoto_cfg($db);
        if (empty($cfg)) return ['status' => 'error', 'message' => 'Kikoto config missing'];

        // Call Kikoto confirm
        $confirmRes = _kikoto_request('POST', '/bookings/' . urlencode($reference) . '/confirm', [
            'cfg'     => $cfg,
            'timeout' => 30,
        ]);

        if (!$confirmRes['ok']) {
            error_log("FERRIES_PAYMENT_WEBHOOK: Kikoto confirm failed for {$reference}: " . ($confirmRes['error'] ?? ''));
            return ['status' => 'error', 'message' => 'Kikoto confirm failed'];
        }

        $confirmed = $confirmRes['data']['data'] ?? [];
        $locators  = [];
        foreach ($confirmed['sailings'] ?? [] as $s) {
            if (!empty($s['locator'])) $locators[] = $s['locator'];
        }

        // Update booking with confirmed data
        $bookingData['confirmed'] = $confirmed;
        $bookingData['locators']  = $locators;

        $db->update('bookings', [
            'booking_status'   => 'confirmed',
            'pnr'              => implode(',', $locators),
            'booking_response' => json_encode($confirmed, JSON_UNESCAPED_SLASHES),
            'booking_data'     => json_encode($bookingData, JSON_UNESCAPED_SLASHES),
            'updated_at'       => date('Y-m-d H:i:s'),
        ], ['invoice_id' => $invoiceId]);

        error_log("FERRIES_PAYMENT_WEBHOOK: Confirmed {$invoiceId}, locators: " . implode(',', $locators));

    } catch (\Throwable $e) {
        error_log("FERRIES_PAYMENT_WEBHOOK ERROR: " . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

return ['status' => 'success', 'message' => "Webhook processed: {$event}"];

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
        $confirmedNet = 0.0;   // supplier EUR net, summed across sailings (+ any fee)
        foreach ($confirmed['sailings'] ?? [] as $s) {
            if (!empty($s['locator'])) $locators[] = $s['locator'];
            $confirmedNet += (float) ($s['price'] ?? 0) + (float) ($s['fee'] ?? 0);
        }

        // POST-PAYMENT PRICE RECONCILE (2026-11-28). Kikoto's /confirm returns the
        // finalized per-sailing price in the SUPPLIER currency (EUR). We compare that
        // confirmed net to the EUR figure we committed to at draft time
        // (booking_data.supplier_final_total), in EUR-vs-EUR so there is NO currency
        // conversion that could false-block a booking. If Kikoto confirmed a HIGHER
        // price than we sold (beyond the per-module fare_tolerance_pct), we FLAG the
        // booking for review instead of silently confirming a loss — but we do NOT
        // auto-cancel (the supplier already issued the locator; unbooking would need a
        // separate cancel). Tolerance defaults to 0 (never absorb a loss) and is
        // admin-tunable via modules.fare_tolerance_pct for kikoto/ferries.
        // supplier_final_total (EUR committed figure) is stored nested under
        // booking_data.draft (bookingRoutes.php wraps the draft under 'draft'); accept
        // the top-level too in case a future path stores it flat.
        $__committedNet = (float) (
            $bookingData['draft']['supplier_final_total']
            ?? $bookingData['supplier_final_total']
            ?? 0
        );
        $__priceReview  = null;
        if ($confirmedNet > 0 && $__committedNet > 0) {
            $__tolPct = function_exists('reconcileModuleTolerancePct')
                ? reconcileModuleTolerancePct($db, $booking)
                : 0.0;
            $__deltaPct = (($confirmedNet - $__committedNet) / $__committedNet) * 100.0;
            if ($__deltaPct > max($__tolPct, 0.0001)) {
                $__priceReview = [
                    'review_state' => 'ferry_price_mismatch',
                    'committed_eur' => round($__committedNet, 2),
                    'confirmed_eur' => round($confirmedNet, 2),
                    'delta_pct'     => round($__deltaPct, 2),
                    'tolerance_pct' => $__tolPct,
                    'flagged_at'    => date('Y-m-d H:i:s'),
                ];
                error_log('FERRIES_PAYMENT_WEBHOOK PRICE REVIEW ' . $invoiceId . ': confirmed EUR ' . round($confirmedNet, 2) . ' vs committed ' . round($__committedNet, 2) . ' (+' . round($__deltaPct, 2) . '% > tol ' . $__tolPct . '%) — flagged, NOT auto-cancelled (locator issued).');
            }
        }

        // Update booking with confirmed data
        $bookingData['confirmed'] = $confirmed;
        $bookingData['locators']  = $locators;
        if ($__priceReview !== null) {
            $bookingData['price_review'] = $__priceReview;
        }

        $__upd = [
            'booking_status'   => 'confirmed',
            'pnr'              => implode(',', $locators),
            'booking_response' => json_encode($confirmed, JSON_UNESCAPED_SLASHES),
            'booking_data'     => json_encode($bookingData, JSON_UNESCAPED_SLASHES),
            'updated_at'       => date('Y-m-d H:i:s'),
        ];
        // Surface the price mismatch where ops reads it (error_response), without
        // changing the confirmed status — the locator is already issued by Kikoto.
        if ($__priceReview !== null) {
            $__upd['error_response'] = json_encode($__priceReview, JSON_UNESCAPED_SLASHES);
        }
        $db->update('bookings', $__upd, ['invoice_id' => $invoiceId]);

        error_log("FERRIES_PAYMENT_WEBHOOK: Confirmed {$invoiceId}, locators: " . implode(',', $locators));

        // NOTIFY THE CUSTOMER — email + WhatsApp with the branded ferry ticket. The
        // webhook previously confirmed the booking but sent NO customer notification
        // (parity gap). bm_notify_paid sends to bookings.email + phone with the
        // no-price ticket voucher (3-document model); best-effort, never blocks.
        if (function_exists('bm_notify_paid')) {
            try { bm_notify_paid($db, (string) $invoiceId); }
            catch (\Throwable $e) { error_log("FERRIES_PAYMENT_WEBHOOK notify: " . $e->getMessage()); }
        }

    } catch (\Throwable $e) {
        error_log("FERRIES_PAYMENT_WEBHOOK ERROR: " . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

return ['status' => 'success', 'message' => "Webhook processed: {$event}"];

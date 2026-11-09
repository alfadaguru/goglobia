<?php
// app/routes/crons/refundRetryRoutes.php
// GAP FIX (Part 1 §1.4 HIGH "Refund-API failure is flagged but never retried",
// 2026-11-09). When _auto_refund_on_issue_failure -> refund_gateway_payment returns
// a non-refunded status, payment-gateway.php:2805 writes
//   booking_payment_issue = 'Issue failed; AUTO-REFUND NOT completed (...) — admin must refund manually.'
// and stops. Nothing re-attempts it, so a charged-but-unissued customer's money sits
// until a human reads the flag. This cron re-invokes refund_gateway_payment (which is
// idempotent via the refund_claimed_at claim and short-circuits an already-refunded
// booking), with BOUNDED retries + a hard-stop so an 'unsupported' gateway can't loop
// forever. Schedule ABOVE the 10-min refund_claimed_at stale window (e.g. every 20 min):
//   GET /refund_retry_sweep
@$SECURE or die('Access Denied!');

$router->get('/refund_retry_sweep', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    $MAX_ATTEMPTS = 5;                 // hard-stop: never retry a booking more than this
    $HARD_STOP_TAG = ' [refund-retry: GAVE UP after ' . $MAX_ATTEMPTS . ' attempts — admin must refund manually]';

    $out = ['status' => true, 'scanned' => 0, 'refunded' => 0, 'still_failing' => 0, 'gave_up' => 0, 'skipped' => 0, 'ran_at' => date('Y-m-d H:i:s')];

    if (!function_exists('refund_gateway_payment')) {
        echo json_encode(array_merge($out, ['status' => false, 'error' => 'refund_gateway_payment unavailable']));
        return;
    }

    // Candidates: money collected, auto-refund flagged-not-completed, not already a
    // terminal refund state, and not already hard-stopped.
    $candidates = $db->select('bookings', ['id', 'invoice_id', 'booking_payment_issue', 'payment_status', 'booking_status'], [
        'booking_payment_issue[~]' => 'AUTO-REFUND NOT completed',
        'payment_status' => 'paid',
        'LIMIT' => 200,
    ]);

    foreach ((array) $candidates as $b) {
        $out['scanned']++;
        $invoiceId = (string) ($b['invoice_id'] ?? '');
        $flag      = (string) ($b['booking_payment_issue'] ?? '');
        if ($invoiceId === '') { $out['skipped']++; continue; }

        // Already given up / already terminal -> skip.
        if (strpos($flag, 'refund-retry: GAVE UP') !== false) { $out['skipped']++; continue; }
        $bs = strtolower((string) ($b['booking_status'] ?? ''));
        if (in_array($bs, ['refunded', 'cancelled', 'voided'], true) && strtolower((string) ($b['payment_status'] ?? '')) === 'refunded') {
            $out['skipped']++; continue;
        }

        // Parse the current attempt count embedded in the flag ("[refund-retry: attempt N]").
        $attempts = 0;
        if (preg_match('/\[refund-retry: attempt (\d+)\]/', $flag, $m)) { $attempts = (int) $m[1]; }

        // Re-fetch the full booking for the refund call.
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) { $out['skipped']++; continue; }

        try {
            $res = refund_gateway_payment($db, $booking, null, 'Auto-refund retry (paid-but-unissued)');
        } catch (\Throwable $e) {
            error_log('refund_retry exception invoice=' . $invoiceId . ' : ' . $e->getMessage());
            $res = ['status' => 'failed', 'message' => $e->getMessage()];
        }

        $st = strtolower((string) ($res['status'] ?? 'failed'));

        if ($st === 'refunded') {
            // refund_gateway_payment already set payment_status='refunded' on success;
            // clear the stale flag so it drops out of future sweeps.
            $db->update('bookings', ['booking_payment_issue' => null], ['invoice_id' => $invoiceId]);
            $out['refunded']++;
            continue;
        }

        if ($st === 'in_progress') {
            // A claim is active (another worker / within the 10-min window). Benign —
            // leave untouched, don't burn an attempt.
            $out['skipped']++;
            continue;
        }

        // 'failed' or 'unsupported' -> a real non-recoverable-right-now outcome.
        $attempts++;
        if ($attempts >= $MAX_ATTEMPTS) {
            // Hard-stop: strip any prior attempt tag, append the GAVE-UP marker so it is
            // excluded from future sweeps and visible to admin.
            $base = preg_replace('/ \[refund-retry: attempt \d+\]/', '', $flag);
            $db->update('bookings', ['booking_payment_issue' => $base . $HARD_STOP_TAG], ['invoice_id' => $invoiceId]);
            error_log('refund_retry GAVE UP invoice=' . $invoiceId . ' after ' . $attempts . ' attempts status=' . $st . ' msg=' . ($res['message'] ?? ''));
            $out['gave_up']++;
        } else {
            $base = preg_replace('/ \[refund-retry: attempt \d+\]/', '', $flag);
            $db->update('bookings', ['booking_payment_issue' => $base . ' [refund-retry: attempt ' . $attempts . ']'], ['invoice_id' => $invoiceId]);
            $out['still_failing']++;
        }
    }

    echo json_encode($out);
});

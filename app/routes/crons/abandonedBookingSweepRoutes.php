<?php
// app/routes/crons/abandonedBookingSweepRoutes.php
// GAP FIX (Part 1 §1.4 MEDIUM "No generic expiry sweep for ordinary abandoned
// bookings", 2026-11-09). Scoped sweeps already exist for Pay-Later
// (/pay_later_sweep), Umrah holds (/umrah_expire_holds) and GIA (/gia_payments_sweep).
// Missing was a sweep for ORDINARY card/Paystack-checkout bookings on
// flights/stays/cars/tours/visa/esim/ferries/rail/bus that are abandoned before
// payment and sit payment_status='unpaid' / booking_status='pending' forever,
// holding own-inventory seats. This cron cancels them after a generous grace and
// releases any stays hold.
//
// HARD EXCLUSIONS (doc "risk of the fix" — never touch an in-flight or opt-in order):
//   - Pay-Later          : payment_due_at IS NOT NULL   (pay_later_sweep owns these)
//   - Umrah              : module_type = 'umrah'        (umrah_booking_expire_sweep)
//   - GIA                : module_type = 'ai_trip'      (gia_payments_sweep)
//   - Installment / PSS  : installments_active_for()    (per-row belt-and-braces)
//   - In-flight payment  : covered by the GRACE window (default 180 min) — a customer
//                          mid-checkout / on a slow 3-DS redirect is well inside it.
//   - Already terminal   : only payment_status='unpaid' + booking_status='pending'.
// booking_status enum is ('confirmed','pending','cancelled') — there is no 'expired'
// value, so an expired order is set 'cancelled' with a cancellation_response note.
// Schedule e.g. every 30-60 min: GET /abandoned_booking_sweep
@$SECURE or die('Access Denied!');

$router->get('/abandoned_booking_sweep', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    // Grace window (minutes) before an unpaid/pending booking is considered abandoned.
    // DB-overridable via settings.abandoned_booking_grace_minutes; clamped 60..10080.
    $grace = 180;
    try {
        $cfg = $db->get('settings', 'abandoned_booking_grace_minutes', ['id' => 1]);
        if ($cfg !== null && $cfg !== '' && is_numeric($cfg)) { $grace = (int) $cfg; }
    } catch (\Throwable $e) { /* column variance -> default */ }
    $grace = max(60, min(10080, $grace)); // never below 1h, never above 7d
    $cutoff = date('Y-m-d H:i:s', time() - ($grace * 60));

    $out = ['status' => true, 'grace_minutes' => $grace, 'scanned' => 0, 'cancelled' => 0, 'holds_released' => 0, 'skipped' => 0, 'ran_at' => date('Y-m-d H:i:s')];

    $rows = $db->select('bookings', [
        'invoice_id', 'module_type', 'payment_status', 'booking_status', 'payment_due_at', 'created_at',
    ], [
        'payment_status'    => 'unpaid',
        'booking_status'    => 'pending',
        'payment_due_at'    => null,                 // excludes Pay-Later (it sets a due date)
        'module_type[!]'    => ['umrah', 'ai_trip'], // excludes Umrah + GIA (own sweeps)
        'created_at[<]'     => $cutoff,
        'LIMIT'             => 300,
    ]) ?: [];

    foreach ($rows as $b) {
        $out['scanned']++;
        $invoiceId = (string) ($b['invoice_id'] ?? '');
        if ($invoiceId === '') { $out['skipped']++; continue; }

        // Belt-and-braces: never cancel a booking on an active installment / PaySmallSmall
        // plan (those legitimately sit unpaid/pending and are driven by their own ledger).
        if (function_exists('installments_active_for') && installments_active_for($db, $invoiceId)) {
            $out['skipped']++;
            continue;
        }

        try {
            // Re-read live state so a booking that JUST got paid between the SELECT and
            // now is never cancelled (TOCTOU guard).
            $live = $db->get('bookings', ['payment_status', 'booking_status'], ['invoice_id' => $invoiceId]);
            if (!$live
                || strtolower((string) ($live['payment_status'] ?? '')) !== 'unpaid'
                || strtolower((string) ($live['booking_status'] ?? '')) !== 'pending') {
                $out['skipped']++;
                continue;
            }

            // Release any own-inventory stays hold so the night is resellable.
            $mt = strtolower((string) ($b['module_type'] ?? ''));
            if (in_array($mt, ['stays', 'hotels'], true) && function_exists('stays_hold_release')) {
                try {
                    if (stays_hold_release($db, $invoiceId)) { $out['holds_released']++; }
                } catch (\Throwable $e) { error_log('abandoned_sweep hold-release ' . $invoiceId . ': ' . $e->getMessage()); }
            }

            $db->update('bookings', [
                'booking_status'        => 'cancelled',
                'cancellation_status'   => 1,
                'cancellation_response' => 'Auto-cancelled: payment not completed within ' . $grace . ' minutes of booking (abandoned-booking sweep).',
            ], ['invoice_id' => $invoiceId]);
            $out['cancelled']++;
        } catch (\Throwable $e) {
            error_log('abandoned_booking_sweep ' . $invoiceId . ': ' . $e->getMessage());
            $out['skipped']++;
        }
    }

    echo json_encode($out);
});

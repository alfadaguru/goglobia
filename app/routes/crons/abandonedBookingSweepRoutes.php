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

    // ── FRONT-DESK BOOK-ON-HOLD EXPIRY ──────────────────────────────────────────
    // A front-desk "held" booking blocks its room with a DEADLINE (hold_expires_at, the
    // property's hold window). This is NOT the grace-window pending case above — it's a
    // hard deadline. When it lapses unpaid, release the room (stays_on_cancelled — the same
    // hook the web/admin cancel uses), cancel the booking, and notify. Idempotent + TOCTOU-
    // guarded. A hold that got CONFIRMED (held→confirmed) is no longer 'held', so it's never
    // swept. NGN/own-inventory only — these are supplier stays walk-ins/phone holds.
    $out['holds_expired'] = 0;
    $nowTs = date('Y-m-d H:i:s');
    $heldRows = $db->select('bookings',
        ['invoice_id', 'module_type', 'email', 'phone', 'phone_country_code', 'first_name', 'last_name', 'price_markup', 'currency_markup'], [
        'booking_status'   => 'held',
        'hold_expires_at[<]'=> $nowTs,
        'LIMIT'            => 300,
    ]) ?: [];
    foreach ($heldRows as $hb) {
        $out['scanned']++;
        $invoiceId = (string) ($hb['invoice_id'] ?? '');
        if ($invoiceId === '') { $out['skipped']++; continue; }
        try {
            // TOCTOU: re-read live — a hold that was JUST confirmed (held→confirmed) between
            // the SELECT and now must NEVER be cancelled.
            $live = $db->get('bookings', ['booking_status', 'hold_expires_at'], ['invoice_id' => $invoiceId]);
            if (!$live || strtolower((string) ($live['booking_status'] ?? '')) !== 'held'
                || empty($live['hold_expires_at']) || strtotime((string) $live['hold_expires_at']) > time()) {
                $out['skipped']++;
                continue;
            }
            // Release the room (consume→... no: it's still 'held', so release frees the night).
            // stays_on_cancelled runs the canonical release (hold → released) + any earning
            // reversal; fall back to stays_hold_release if the hook isn't loaded.
            $mt = strtolower((string) ($hb['module_type'] ?? ''));
            if (in_array($mt, ['stays', 'hotels'], true)) {
                try {
                    if (function_exists('stays_on_cancelled')) { stays_on_cancelled($db, $invoiceId); }
                    elseif (function_exists('stays_hold_release')) { stays_hold_release($db, $invoiceId); }
                    $out['holds_released']++;
                } catch (\Throwable $e) { error_log('held_sweep release ' . $invoiceId . ': ' . $e->getMessage()); }
            }
            // Cancel the booking (state-guarded: only flip a row still 'held').
            $db->update('bookings', [
                'booking_status'        => 'cancelled',
                'cancellation_status'   => 1,
                'cancellation_response' => 'Auto-released: hold expired without confirmation (front-desk book-on-hold sweep).',
                'hold_expires_at'       => null,
                'updated_at'            => $nowTs,
            ], ['invoice_id' => $invoiceId, 'booking_status' => 'held']);
            $out['cancelled']++;
            $out['holds_expired']++;
            // Notify the guest the hold lapsed (best-effort, never blocks the sweep). Same
            // NOTIFY::cancellation($module, $customerData, $bookingData) signature the web
            // stays-cancel path uses (app/routes/stays/bookingRoutes.php).
            try {
                if (class_exists('NOTIFY') && method_exists('NOTIFY', 'cancellation')) {
                    NOTIFY::cancellation('stays', [
                        'email'        => $hb['email'] ?? '',
                        'phone'        => $hb['phone'] ?? '',
                        'first_name'   => $hb['first_name'] ?? '',
                        'last_name'    => $hb['last_name'] ?? '',
                        'country_code' => $hb['phone_country_code'] ?? '',
                    ], [
                        'invoice_id'  => $invoiceId,
                        'amount'      => $hb['price_markup'] ?? 0,
                        'currency'    => $hb['currency_markup'] ?? 'USD',
                        'module_type' => 'Stay',
                    ]);
                }
            } catch (\Throwable $e) { error_log('held_sweep notify ' . $invoiceId . ': ' . $e->getMessage()); }
        } catch (\Throwable $e) {
            error_log('held_booking_sweep ' . $invoiceId . ': ' . $e->getMessage());
            $out['skipped']++;
        }
    }

    echo json_encode($out);
});

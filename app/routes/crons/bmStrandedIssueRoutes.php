<?php
/**
 * CRON JOB: Booking Manager stranded-issue auto-recovery
 * FILE: app/routes/crons/bmStrandedIssueRoutes.php
 *
 * PROBLEM:
 * A Booking Manager / Agent LIVE booking (eSIM, flights, stays, …) is marked
 * PAID, then the supplier issue is fired. If that issue FAILS (supplier down,
 * timeout, rate limit), the booking is left PAID-but-UNISSUED: money collected,
 * no PNR, booking_status != 'confirmed'. Until now recovery was only a MANUAL
 * senior click on the verify page — so a transient failure could sit stranded
 * until a human noticed.
 *
 * THIS CRON re-fires the supplier issue for every stranded booking, so a
 * transient failure self-heals. It is SAFE to run repeatedly:
 *   - bm_settle_booking_payment() takes the ISSUE-ONLY recovery path for an
 *     already-paid live booking with no PNR — it does NOT re-charge or re-audit
 *     (see app/lib/booking_manager.php: $needsIssueOnly).
 *   - the supplier issue.php holds a concurrency claim (issue_claimed_at), so an
 *     in-flight issue is not double-fired.
 * On success the booking becomes 'confirmed' with a PNR and drops out of the
 * stranded set. A still-failing booking stays stranded for the next run / a
 * senior to refund.
 *
 * STRANDED SIGNAL (identical to the verify page's ops queue):
 *   payment_status = 'paid' AND booking_status != 'confirmed'
 *   AND booking_channel LIKE '%admin_live%'  (BM/agent live bookings)
 *
 * CRON SETUP (every ~10 min on the server) — crontab line:
 *   every 10 min:  curl -s "https://yourdomain.com/cron/bm-stranded-issue" > /dev/null 2>&1
 *
 * ROUTE: GET /cron/bm-stranded-issue
 */

@$SECURE or die('Access Denied!');

$router->get('/cron/bm-stranded-issue', function () use ($SECURE, $db) {

    @set_time_limit(0);
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');

    require_once dirname(__DIR__, 3) . '/app/lib/booking_manager.php';

    if (!function_exists('bm_settle_booking_payment')) {
        echo json_encode([
            'success' => false,
            'run_at'  => date('Y-m-d H:i:s'),
            'message' => 'Booking Manager settle function unavailable.',
        ]);
        exit;
    }

    // Stranded = PAID live booking that never got a PNR. Cap the batch; the next
    // run picks up any remainder. Skip rows whose issue is actively in flight
    // (issue_claimed_at within the last 10 min) so we never race a live attempt.
    $cols = ['invoice_id', 'module', 'module_type', 'price_markup', 'currency_markup', 'issue_claimed_at'];
    $stranded = $db->select('bookings', $cols, [
        'payment_status'     => 'paid',
        'booking_status[!]'  => 'confirmed',
        'booking_channel[~]' => 'admin_live',
        'ORDER'              => ['id' => 'ASC'],
        'LIMIT'              => 50,
    ]) ?: [];

    $attempted = 0; $recovered = 0; $skipped = 0; $stillStranded = 0;
    $results = [];

    foreach ($stranded as $b) {
        $inv = (string) ($b['invoice_id'] ?? '');
        if ($inv === '') { continue; }

        // Skip if an issue is actively in flight (claimed < 10 min ago).
        $claimedAt = (string) ($b['issue_claimed_at'] ?? '');
        if ($claimedAt !== '' && strtotime($claimedAt) !== false && strtotime($claimedAt) > (time() - 600)) {
            $skipped++;
            continue;
        }

        $attempted++;
        try {
            // method is cosmetic on the recovery path; bm_settle takes the
            // issue-only branch (no re-charge) for a paid live booking with no PNR.
            $res = bm_settle_booking_payment($db, $inv, 'manual_verified', [
                'amount'   => (float) ($b['price_markup'] ?? 0),
                'currency' => (string) ($b['currency_markup'] ?? 'NGN'),
            ]);
            $ok = !empty($res['ok']) && !empty($res['issued']);
            if ($ok) { $recovered++; } else { $stillStranded++; }
            $results[] = ['invoice_id' => $inv, 'module' => (string) ($b['module'] ?? ''), 'recovered' => $ok, 'message' => (string) ($res['message'] ?? '')];
        } catch (\Throwable $e) {
            $stillStranded++;
            error_log('bm-stranded-issue ' . $inv . ': ' . $e->getMessage());
            $results[] = ['invoice_id' => $inv, 'recovered' => false, 'message' => 'exception: ' . $e->getMessage()];
        }
    }

    // ------------------------------------------------------------------
    // SECOND SWEEP: un-notified settled bookings (deferred-notify backstop).
    // The interactive /pay + /verify paths defer bm_notify_paid to AFTER the
    // response flushes. If the worker dies in that post-response window (deploy
    // kill, mPDF OOM, time-limit) the customer never gets their ticket/voucher —
    // and the stranded sweep above does NOT catch it (it only looks at
    // non-confirmed admin_live rows, so a CONFIRMED or manual booking is
    // invisible). bm_notify_paid's crash-release guard clears its claim on such a
    // failure, leaving notified_paid_at NULL — so here we simply re-drive
    // bm_notify_paid for any PAID booking that was never notified. It is fully
    // idempotent (atomic notified_paid_at claim) and its own fulfilment guard
    // skips a paid-but-unissued live booking, so this is safe to run every tick.
    $renotified = 0; $renotifyFound = 0;
    try {
        // EXACT-match the BM/agent channels — NOT Medoo [~] LIKE: the '_' in
        // 'admin_live'/'admin_manual' defeats Medoo's %..% auto-wrap (known trap), so a
        // LIKE 'admin_%' would silently match nothing. An IN on the exact values is
        // unambiguous and covers both BM channels (live + manual).
        $unnotified = $db->select('bookings', ['invoice_id'], [
            'payment_status'   => 'paid',
            'notified_paid_at'  => null,
            'booking_channel'  => ['admin_live', 'admin_manual'],
            'ORDER'            => ['id' => 'ASC'],
            'LIMIT'            => 50,
        ]) ?: [];
        $renotifyFound = count($unnotified);
        foreach ($unnotified as $u) {
            $uinv = (string) ($u['invoice_id'] ?? '');
            if ($uinv === '') { continue; }
            try {
                if (function_exists('bm_notify_paid')) { bm_notify_paid($db, $uinv); $renotified++; }
            } catch (\Throwable $e) {
                error_log('bm-renotify ' . $uinv . ': ' . $e->getMessage());
            }
        }
    } catch (\Throwable $e) { error_log('bm-renotify sweep: ' . $e->getMessage()); }

    echo json_encode([
        'success'        => true,
        'run_at'         => date('Y-m-d H:i:s'),
        'found'          => count($stranded),
        'attempted'      => $attempted,
        'recovered'      => $recovered,
        'in_flight_skip' => $skipped,
        'still_stranded' => $stillStranded,
        'renotify_found' => $renotifyFound,
        'renotified'     => $renotified,
        'results'        => $results,
        'message'        => sprintf('BM stranded-issue sweep: %d found, %d recovered, %d still stranded, %d in-flight skipped; renotify: %d found, %d sent.',
            count($stranded), $recovered, $stillStranded, $skipped, $renotifyFound, $renotified),
    ], JSON_UNESCAPED_SLASHES);
    exit;
});

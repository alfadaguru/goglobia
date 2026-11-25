<?php
// ============================================================================
// FILE: app/routes/crons/tiqwaCompleteRoutes.php
// TIQWA PAID-BUT-UNTICKETED COMPLETION SWEEP  (/cron/tiqwa-complete)
// ----------------------------------------------------------------------------
// Audit CRITICAL-2 backstop. Tiqwa /pay is ASYNCHRONOUS: on a successful pay the
// booking is written paid + booking_status='pending' with pnr='' and the stored
// supplier reference (modules/flights/tiqwa/actions/issue.php). The real PNR /
// ticket number arrives LATER, and until now the ONLY things that filled it were
// (a) the inbound ticket.issued webhook and (b) a manual admin booking-details
// poll. If that webhook is never registered, or Tiqwa's delivery fails/retries
// exhaust, the customer is left CHARGED with NO ticket and NO auto-refund,
// stranded indefinitely with no automatic recovery.
//
// This cron removes the single-point webhook dependency: it periodically re-reads
// Tiqwa for every paid+pending Tiqwa booking that still has no PNR, using the SAME
// authenticated read-back helper the webhook uses (tiqwa_complete_from_booking_details
// -> GET /{reference}; status===issued => write pnr + confirm + notify). Idempotent:
// a booking that already has a pnr is skipped, and the helper no-ops if already done.
//
// Schedule every few minutes:  GET /cron/tiqwa-complete
// (Mirrors the cron-auth + loop shape of bmStrandedIssueRoutes.php.)
// ============================================================================
@$SECURE or die('Access Denied!');

$router->get('/cron/tiqwa-complete', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    // Load the completion helper (lives in the Tiqwa module).
    if (!function_exists('tiqwa_complete_from_booking_details')) {
        $creds = dirname(__DIR__, 3) . '/modules/flights/tiqwa/creds.php';
        if (is_file($creds)) require_once $creds;      // getTiqwaCredentials + tiqwaApiRequest
        $util = dirname(__DIR__, 3) . '/modules/flights/tiqwa/utilities.php';
        if (is_file($util)) require_once $util;         // tiqwa_complete_from_booking_details
    }
    if (!function_exists('tiqwa_complete_from_booking_details')) {
        echo json_encode(['status' => false, 'message' => 'Tiqwa completion helper unavailable']);
        exit;
    }

    // Stranded = a Tiqwa booking that is PAID, still PENDING, has no PNR yet, and is
    // old enough that the webhook has had a fair chance (grace window). module is set
    // to 'tiqwa' by the issue path; module_type stays 'flights'.
    $graceMinutes = 3;   // give the webhook a few minutes before the sweep steps in
    $maxAgeHours  = 72;  // stop re-polling very old rows (Tiqwa reservations auto-expire)

    // Compute the time bounds IN SQL (NOW() - INTERVAL) rather than from PHP date()/
    // strtotime(): PHP and MySQL can run on different clocks (verified ~1h skew on this
    // host), so PHP-computed datetime bounds silently excluded genuinely-stranded rows.
    // Using Medoo raw against the DB's own NOW() removes any PHP<->MySQL clock dependency.
    $candidates = $db->select('bookings',
        ['id', 'invoice_id', 'booking_data', 'pnr', 'booking_status', 'payment_status', 'created_at'],
        [
            'module'         => 'tiqwa',
            'payment_status' => 'paid',
            'booking_status' => 'pending',
            'created_at[>]'  => Medoo\Medoo::raw("(NOW() - INTERVAL {$maxAgeHours} HOUR)"),   // not too old
            'created_at[<]'  => Medoo\Medoo::raw("(NOW() - INTERVAL {$graceMinutes} MINUTE)"), // past the webhook grace
            'ORDER'          => ['id' => 'ASC'],
            'LIMIT'          => 50,
        ]
    );

    $checked = 0; $completed = 0; $stillPending = 0; $errors = 0; $skipped = 0;
    foreach (($candidates ?: []) as $b) {
        // Skip if a PNR already landed (race with the webhook).
        if (trim((string) ($b['pnr'] ?? '')) !== '') { $skipped++; continue; }

        $bd = json_decode((string) ($b['booking_data'] ?? ''), true);
        $reference = trim((string) ($bd['supplier_ref'] ?? $bd['tiqwa_reference'] ?? ''));
        if ($reference === '') { $skipped++; continue; }  // no reference → nothing to poll

        $checked++;
        try {
            $r = tiqwa_complete_from_booking_details($db, $reference, (string) $b['invoice_id']);
            // The helper writes pnr + confirms + notifies when status===issued.
            if (!empty($r['status']) && (!empty($r['pnr']) || ($r['confirmed'] ?? false) || stripos((string) ($r['message'] ?? ''), 'issued') !== false)) {
                $completed++;
            } else {
                $stillPending++;
            }
        } catch (\Throwable $e) {
            $errors++;
            error_log('TIQWA COMPLETE SWEEP | ' . $b['invoice_id'] . ' ref=' . $reference . ' error: ' . $e->getMessage());
        }
    }

    echo json_encode([
        'status'       => true,
        'candidates'   => count($candidates ?: []),
        'checked'      => $checked,
        'completed'    => $completed,
        'still_pending'=> $stillPending,
        'skipped'      => $skipped,
        'errors'       => $errors,
    ]);
    exit;
});

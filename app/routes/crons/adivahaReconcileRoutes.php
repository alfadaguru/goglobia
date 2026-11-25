<?php
// ============================================================================
// FILE: app/routes/crons/adivahaReconcileRoutes.php
// ADIVAHA HELD-FOR-REVIEW RECONCILIATION SWEEP  (/cron/adivaha-reconcile)
// ----------------------------------------------------------------------------
// Audit HIGH-2 backstop. When a post-payment Adivaha /flights/book returns 5xx
// (500 = "airline confirmed a PNR but local persistence failed" => ticket IS
// issued; 502/503 = ambiguous upstream error => MAY or may not have booked) OR
// lands in Adivaha status on_hold, the issue action deliberately does NOT
// auto-refund (returns status:true) and holds the booking paid + pending for
// manual reconciliation (modules/flights/adivaha/actions/issue.php). Correct for
// the 500/issued case — but there was NO automated reconciliation, so a 502/503
// that did NOT actually book, or an on_hold that never confirmed, left the
// customer CHARGED with no ticket and no refund, stranded on manual ops.
//
// This cron re-reads Adivaha for each such held booking (booking-details read-back
// with OUR bearer — the authoritative source) and resolves it:
//   • airline PNR / ticketed  -> write pnr, booking_status='confirmed', notify.
//   • still on_hold / pending  -> leave for the next sweep (within max-age).
//   • Adivaha has NO such booking (confirmed not-booked, past grace) -> mark for
//     refund: set a refund marker the refund-retry sweep / ops will action, so a
//     genuinely-unbooked charged customer is not stranded forever.
//
// Schedule every few minutes:  GET /cron/adivaha-reconcile
// (Mirrors app/routes/crons/tiqwaCompleteRoutes.php.)
// ============================================================================
@$SECURE or die('Access Denied!');

$router->get('/cron/adivaha-reconcile', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    // Load the Adivaha API helpers (creds + request).
    if (!function_exists('adivahaApiRequest') || !function_exists('getAdivahaCredentials') || !function_exists('adivahaBearer')) {
        $c = dirname(__DIR__, 3) . '/modules/flights/adivaha/creds.php';
        if (is_file($c)) require_once $c;
    }
    if (!function_exists('adivahaApiRequest') || !function_exists('adivahaBearer')) {
        echo json_encode(['status' => false, 'message' => 'Adivaha API helpers unavailable']);
        exit;
    }

    $graceMinutes = 5;   // let the inline read-back + any on_hold settle first
    $maxAgeHours  = 72;  // stop sweeping very old rows

    // Held Adivaha bookings: paid + pending + no local PNR yet, past the grace window.
    $candidates = $db->select('bookings',
        ['id', 'invoice_id', 'booking_data', 'pnr', 'currency_markup', 'price_markup'],
        [
            'module'         => 'adivaha',
            'payment_status' => 'paid',
            'booking_status' => 'pending',
            'created_at[>]'  => Medoo\Medoo::raw("(NOW() - INTERVAL {$maxAgeHours} HOUR)"),
            'created_at[<]'  => Medoo\Medoo::raw("(NOW() - INTERVAL {$graceMinutes} MINUTE)"),
            'ORDER'          => ['id' => 'ASC'],
            'LIMIT'          => 50,
        ]
    );

    $creds  = function_exists('getAdivahaCredentials') ? getAdivahaCredentials($db) : null;
    $bearer = $creds ? adivahaBearer($db, $creds) : null;
    $base   = $creds['base_url'] ?? ($creds['flight_base'] ?? $creds['base'] ?? '');
    if (!$bearer || $base === '') {
        echo json_encode(['status' => false, 'message' => 'Adivaha not configured']);
        exit;
    }

    $checked = 0; $confirmed = 0; $stillPending = 0; $flaggedRefund = 0; $skipped = 0; $errors = 0;
    foreach (($candidates ?: []) as $b) {
        if (trim((string) ($b['pnr'] ?? '')) !== '') { $skipped++; continue; }
        $bd  = json_decode((string) ($b['booking_data'] ?? ''), true);
        $ref = trim((string) ($bd['supplier_ref'] ?? $bd['order_id'] ?? ''));
        $inv = (string) $b['invoice_id'];

        // No reference to read back: a 502/503 that never returned a booking id.
        // Flag for refund so a charged-but-unbooked customer is not stranded.
        if ($ref === '') {
            $bd['review_state'] = 'adivaha_unbooked_no_reference';
            $db->update('bookings', [
                'booking_data'          => json_encode($bd),
                'booking_payment_issue' => 'Adivaha returned no booking reference after payment (5xx) — AUTO-REFUND NOT completed; admin must refund manually.',
            ], ['invoice_id' => $inv]);
            $flaggedRefund++; continue;
        }

        $checked++;
        try {
            $res = adivahaApiRequest('GET', $base . '/flights/booking-details?reference=' . urlencode($ref), null, $bearer, [], 30);
            adivahaLog('cron/reconcile-booking-details', ['reference' => $ref], $res['raw'], $res['http_code'], $res['curl_error'], $inv);
            $data = is_array($res['data'] ?? null) ? ($res['data']['data'] ?? $res['data']) : null;
            $pnr  = is_array($data) ? trim((string) ($data['pnr'] ?? '')) : '';
            $stat = is_array($data) ? strtolower((string) ($data['status'] ?? '')) : '';

            if (!empty($res['success']) && $pnr !== '') {
                // Real PNR landed → confirm.
                $bd['supplier'] = 'adivaha';
                $db->update('bookings', [
                    'pnr'                   => $pnr,
                    'booking_status'        => 'confirmed',
                    'booking_data'          => json_encode($bd),
                    'booking_payment_issue' => null,
                ], ['invoice_id' => $inv]);
                $confirmed++;
                if (function_exists('bm_notify_paid')) { try { bm_notify_paid($db, $inv); } catch (\Throwable $e) {} }
            } elseif (!empty($res['success']) && ($stat === 'on_hold' || $stat === 'pending' || $stat === 'processing')) {
                // Real booking exists, airline not yet confirmed → wait for next sweep.
                $stillPending++;
            } elseif ($res['http_code'] === 404 || (isset($res['data']['error']) && stripos((string) $res['data']['error'], 'not found') !== false)) {
                // Adivaha has NO such booking → the 5xx did NOT book. Charged-but-unbooked
                // → flag for refund so the customer is made whole.
                $bd['review_state'] = 'adivaha_unbooked_confirmed';
                $db->update('bookings', [
                    'booking_data'          => json_encode($bd),
                    'booking_payment_issue' => 'Adivaha has no booking for this reference — not booked; AUTO-REFUND NOT completed; admin must refund manually.',
                ], ['invoice_id' => $inv]);
                $flaggedRefund++;
            } else {
                // Ambiguous (supplier error on read-back) → leave for next sweep.
                $stillPending++;
            }
        } catch (\Throwable $e) {
            $errors++;
            error_log('ADIVAHA RECONCILE SWEEP | ' . $inv . ' ref=' . $ref . ' error: ' . $e->getMessage());
        }
    }

    echo json_encode([
        'status'         => true,
        'candidates'     => count($candidates ?: []),
        'checked'        => $checked,
        'confirmed'      => $confirmed,
        'still_pending'  => $stillPending,
        'flagged_refund' => $flaggedRefund,
        'skipped'        => $skipped,
        'errors'         => $errors,
    ]);
    exit;
});

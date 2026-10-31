<?php
// ============================================================================
// TRAVELSURE ISSUE — answers the payment-gateway loopback after payment.
// POST modules/insurance/travelsure/issue   body: invoice_id
//
// Mirrors modules/esim/airalo/issue.php's CONTRACT (the response shape the
// payment-gateway expects): {status, Prn, reference, booking_status, message}.
//
// STUB behaviour: with no live supplier wired (travelsure_is_configured false),
// we DO NOT fabricate a policy number or claim 'confirmed'. We keep the money
// (already captured), mark the booking 'pending' with a clear review flag, and
// return status:false so the gateway's paid-but-unissued handling kicks in
// (the stranded-issue cron / an admin can finalise once a provider is wired or
// a policy is issued manually). This is the honest stub: no fake fulfilment.
// When the real provider is wired, replace the STUB block with the live
// _travelsure_request('POST','/policy',...) call + reconcilePostPaymentPrice
// (same pattern as airalo issue.php).
// ============================================================================

@$SECURE or die('Access Denied!');

global $router;

$router->post('insurance/travelsure/issue', function () use ($db) {
    header('Content-Type: application/json; charset=utf-8');
    while (ob_get_level()) { ob_end_clean(); }

    try {
        $invoiceId = trim((string) ($_POST['invoice_id'] ?? ''));
        if ($invoiceId === '') {
            throw new Exception('Invoice ID is required');
        }

        $booking = $db->get('bookings', '*', [
            'invoice_id'  => $invoiceId,
            'module_type' => 'insurance',
        ]);
        if (!$booking) {
            throw new Exception('Insurance booking not found');
        }

        // Idempotency: already issued → return the existing policy ref.
        if (!empty($booking['pnr']) && strtolower((string) $booking['booking_status']) === 'confirmed') {
            echo json_encode([
                'status'    => true,
                'Prn'       => $booking['pnr'],
                'reference' => $booking['pnr'],
                'booking_status' => 'confirmed',
                'message'   => 'Policy already issued',
                'already_issued' => true,
                'response_error' => '',
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $cfg = _travelsure_cfg($db);

        // ── STUB: no live supplier wired ──────────────────────────────────────
        if (!function_exists('travelsure_is_configured') || !travelsure_is_configured($cfg)) {
            // Money is captured but there is no provider to issue against. Do NOT
            // fake a policy. Flag for manual finalisation and keep it un-issued.
            try {
                $db->update('bookings', [
                    'booking_status' => 'pending',
                    'error_response' => json_encode([
                        'review_state' => 'insurance_supplier_pending',
                        'reason'       => 'Paid travel-insurance supplier is not yet wired (scaffolding). '
                                        . 'Issue the policy with the provider and record its policy number, '
                                        . 'or refund — the payment is held.',
                        'flagged_at'   => date('Y-m-d H:i:s'),
                    ]),
                ], ['invoice_id' => $invoiceId]);
            } catch (\Throwable $e) {
                error_log('travelsure issue flag: ' . $e->getMessage());
            }
            echo json_encode([
                'status'         => false,
                'message'        => 'Travel-insurance policy could not be auto-issued (supplier not yet wired). The payment is held and the booking is flagged for manual issuance.',
                'response_error' => 'supplier_pending',
                'booking_status' => 'pending',
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        // ── LIVE WIRING (drop-in when the provider is known) ──────────────────
        // 1. Re-price for price-trust (mirror airalo): travelsure_authoritative_price
        //    → convert to booking currency_markup → reconcilePostPaymentPrice; hold
        //    'pending' on an adverse move instead of issuing at a loss.
        // 2. $res = _travelsure_request($db,'POST','/policy',['json'=>$policyPayload]);
        // 3. On success: $policyNo = $res['data']['policy_number'];
        //    $db->update('bookings', ['pnr'=>$policyNo,'booking_status'=>'confirmed',
        //       'booking_response'=>json_encode($res['data']),'error_response'=>null],
        //       ['invoice_id'=>$invoiceId]);
        //    echo {status:true, Prn:$policyNo, reference:$policyNo, booking_status:'confirmed'}
        // 4. On failure: leave 'pending' + error_response, status:false.
        throw new Exception('Insurance supplier client not wired.');
    } catch (Throwable $e) {
        error_log('TRAVELSURE ISSUE ERROR | Invoice: ' . ($_POST['invoice_id'] ?? 'N/A') . ' | ' . $e->getMessage());
        echo json_encode([
            'status'         => false,
            'message'        => $e->getMessage(),
            'response_error' => $e->getMessage(),
        ], JSON_UNESCAPED_SLASHES);
    }
    exit;
});

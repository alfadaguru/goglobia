<?php
// ============================================================================
// GIA commit orchestration (BRD Phase C — the money layer).
//
// DESIGN (grounded in the Phase C.0 source audit, §36.11): GIA is the
// APPROVAL-GATED FRONT DOOR to GoGlobia's EXISTING, verified payment engine — it
// does NOT re-implement payment, gateway verification, or supplier issue. The
// platform already does those robustly:
//   - process_payment($invoiceId)            -> builds the gateway charge (server-side
//                                               actor/currency guards, per-service creds)
//   - handle_payment_callback(token,...)     -> server-to-server RE-VERIFIES capture
//                                               with the gateway, then cURLs the signed
//                                               internal modules/<svc>/<supplier>/issue
//   - supplier_action_guard()                -> guards the issue endpoint (verified real)
//
// GIA adds exactly three things on top:
//   1. The APPROVAL GATE: no money step runs unless gia_verify_approval() passes
//      (approved + unexpired + live price STILL == the frozen snapshot). (BRD PAY-002/3)
//   2. EVIDENCE-GATED truth: GIA never asserts paid/confirmed. It reads the
//      AUTHORITATIVE bookings row and reports CONFIRMED only with a PNR/supplier ref;
//      a `partial` / missing-PNR / error_response state is reported as PARTIAL/
//      UNCONFIRMED and flagged for ops — never "all booked". (BRD R-02, AC-004/007)
//   3. Pre-commit guards for the two real gaps the audit found that GIA must NOT
//      inherit: refuse a zero-priced line; require a prior approval.
//
// NO money moves from inside this file except by calling the platform's own
// process_payment(); and only AFTER the approval gate. This file is pure
// orchestration + truthful state reading.
// ============================================================================

if (!function_exists('gia_commit_initiate_payment')) {
    /**
     * Start payment for an ALREADY-CREATED booking (its `bookings` row + invoice_id
     * must already exist — created by the existing submit flow). Gated on approval.
     *
     * @param array $liveQuote the CURRENT quote (re-hashed vs the snapshot).
     * @return array ['ok'=>bool,'redirect_url'?=>,'invoice_id'?=>,'amount'?=>,
     *                'error'?=>,'error_code'?=>]
     */
    function gia_commit_initiate_payment($db, string $checkoutRef, string $approvalToken, string $invoiceId, array $liveQuote, ?string $userId = null): array
    {
        require_once __DIR__ . '/gia_checkout.php';

        // 1) APPROVAL GATE — the whole point. Refuses if not approved, expired, or
        //    the live price no longer matches the frozen snapshot (PRICE_CHANGED).
        $gate = \gia_verify_approval($db, $checkoutRef, $approvalToken, $liveQuote, $userId);
        if (empty($gate['ok'])) {
            return [
                'ok' => false,
                'error' => (string) ($gate['error'] ?? 'Approval could not be verified.'),
                'error_code' => (string) ($gate['error_code'] ?? 'APPROVAL_REQUIRED'),
            ];
        }

        // 2) Zero-price guard (gap the audit found — do not let a zero line pay/issue).
        $amount = 0.0;
        foreach ((array) ($liveQuote['items'] ?? []) as $it) {
            $amount += (float) ($it['final_base'] ?? $it['price_with_tax_base'] ?? $it['price_base'] ?? $it['price'] ?? 0);
        }
        if ((float) ($liveQuote['total'] ?? $liveQuote['final_base'] ?? $amount) <= 0) {
            return ['ok' => false, 'error' => 'This trip has no payable total.', 'error_code' => 'ZERO_PRICE'];
        }

        // 3) The booking row must exist and not already be paid (idempotency).
        $booking = $db->get('bookings', ['invoice_id', 'payment_status', 'booking_status', 'currency_markup', 'price_markup'], ['invoice_id' => $invoiceId]);
        if (!$booking) {
            return ['ok' => false, 'error' => 'No booking was found to pay for. Create the booking first.', 'error_code' => 'BOOKING_NOT_FOUND'];
        }
        if ((string) ($booking['payment_status'] ?? '') === 'paid') {
            // Idempotent: already paid — surface the existing state, do not re-charge.
            return [
                'ok' => true,
                'invoice_id' => $invoiceId,
                'already_paid' => true,
                'redirect_url' => (defined('root') ? root : '/') . 'invoice/' . $invoiceId,
                'message' => 'This booking is already paid.',
            ];
        }

        // 4) Hand off to the platform's REAL payment engine. process_payment() applies
        //    the actor/currency/per-service-credential guards and builds the gateway
        //    charge; GIA does not reimplement any of that.
        if (!function_exists('process_payment')) {
            $pg = dirname(__DIR__) . '/payment-gateway.php';
            if (is_file($pg)) {
                require_once $pg;
            }
        }
        if (!function_exists('process_payment')) {
            return ['ok' => false, 'error' => 'Payment engine is unavailable.', 'error_code' => 'PAYMENT_UNAVAILABLE'];
        }

        // Mark the checkout CONSUMED so the same approval can't start two charges.
        try {
            $db->update('gia_checkouts', ['status' => 'CONSUMED', 'updated_at' => date('Y-m-d H:i:s')], ['checkout_ref' => $checkoutRef]);
        } catch (\Throwable $e) {
            error_log('[gia] commit consume: ' . $e->getMessage());
        }

        try {
            $res = \process_payment($invoiceId); // existing engine
        } catch (\Throwable $e) {
            error_log('[gia] process_payment: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not start the payment. Please try again.', 'error_code' => 'PAYMENT_START_FAILED'];
        }

        if (empty($res['success'])) {
            return ['ok' => false, 'error' => (string) ($res['message'] ?? 'Payment could not be started.'), 'error_code' => 'PAYMENT_REFUSED'];
        }

        return [
            'ok' => true,
            'invoice_id'   => $invoiceId,
            'redirect_url' => (string) ($res['redirect_url'] ?? ((defined('root') ? root : '/') . 'invoice/' . $invoiceId)),
            'amount'       => (float) ($booking['price_markup'] ?? 0),
            'currency'     => (string) ($booking['currency_markup'] ?? ''),
            'message'      => 'Payment started. Complete it on the secure checkout page.',
        ];
    }
}

if (!function_exists('gia_commit_order_state')) {
    /**
     * TRUTHFUL order-state reader (BRD R-02 evidence-gated; AC-004/AC-007). Reads the
     * AUTHORITATIVE bookings row — GIA never asserts success itself. Returns a state
     * GIA can report honestly:
     *   CONFIRMED      — paid AND has a PNR/supplier ref AND status confirmed, no partial flag
     *   PARTIAL        — some items issued, others failed (customer charged, not fully booked)
     *   PAID_PENDING   — paid but no PNR yet (issuance pending / in progress)
     *   UNPAID         — not yet paid
     *   FAILED         — error state with no PNR
     *   NOT_FOUND
     */
    function gia_commit_order_state($db, string $invoiceId): array
    {
        $b = $db->get('bookings', ['invoice_id', 'payment_status', 'booking_status', 'pnr', 'error_response', 'booking_data', 'module_type'], ['invoice_id' => $invoiceId]);
        if (!$b) {
            return ['ok' => false, 'state' => 'NOT_FOUND', 'message' => 'No booking found for that reference.'];
        }

        $paid = (string) ($b['payment_status'] ?? '') === 'paid';
        $confirmed = (string) ($b['booking_status'] ?? '') === 'confirmed';
        $pnr = trim((string) ($b['pnr'] ?? ''));
        $hasPnr = $pnr !== '';

        // Detect a PARTIAL multi-item outcome from the stored package data / error.
        $partial = false;
        $failedItems = [];
        $data = json_decode((string) ($b['booking_data'] ?? ''), true);
        if (is_array($data) && !empty($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $it) {
                $st = (string) ($it['issue_status'] ?? '');
                if ($st === 'failed') {
                    $partial = true;
                    $failedItems[] = (string) ($it['module'] ?? '') . ($it['supplier'] ? '/' . $it['supplier'] : '');
                }
            }
        }
        // error_response present alongside some success is a partial signal too.
        if (!empty($b['error_response']) && $hasPnr) {
            $partial = true;
        }

        if (!$paid) {
            return ['ok' => true, 'state' => 'UNPAID', 'invoice_id' => $invoiceId, 'message' => 'Payment has not been completed.'];
        }
        if ($partial) {
            return [
                'ok' => true,
                'state' => 'PARTIAL',
                'invoice_id' => $invoiceId,
                'pnr' => $pnr,
                'failed_items' => $failedItems,
                'message' => 'Some parts of this trip were booked and others failed. This needs attention — do not treat it as fully confirmed.',
                'needs_ops' => true,
            ];
        }
        if ($confirmed && $hasPnr) {
            return ['ok' => true, 'state' => 'CONFIRMED', 'invoice_id' => $invoiceId, 'pnr' => $pnr, 'message' => 'Booking confirmed.'];
        }
        if ($paid && !$hasPnr) {
            return ['ok' => true, 'state' => 'PAID_PENDING', 'invoice_id' => $invoiceId, 'message' => 'Payment received; the booking confirmation is still being issued.'];
        }
        // Paid but an error and no PNR.
        return [
            'ok' => true,
            'state' => 'FAILED',
            'invoice_id' => $invoiceId,
            'message' => 'Payment was taken but the booking did not issue. This needs support attention.',
            'needs_ops' => true,
        ];
    }
}

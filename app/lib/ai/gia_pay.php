<?php
// ============================================================================
// FILE: app/lib/ai/gia_pay.php
// GIA in-chat payment helpers (Phase 3). Two paths, both reusing the platform's
// existing, guarded payment engine — this file NEVER moves money itself:
//
//   WALLET  : if a VERIFIED user has enough wallet balance, assign the booking's
//             gateway to the internal_wallet gateway and let process_payment()
//             (via initiate_payment) debit through the audited wallet spine.
//   PAY-LINK: otherwise, a signed + expiring link to the hosted /gia/pay/<invoice>
//             page where they pay on the real gateway. The signature is HMAC over
//             (invoice, expiry) with the existing GIA checkout secret.
//
// All FAIL-SOFT and read-only except gia_pay_assign_wallet_gateway (which only
// sets which gateway a booking uses — still no charge; the charge is process_payment).
// ============================================================================

if (!function_exists('gia_pay_wallet_gateway_id')) {
    /** The active internal_wallet gateway id, or 0 if none configured. */
    function gia_pay_wallet_gateway_id($db): int
    {
        try {
            $g = $db->get('payment_gateways', ['id'], ['type' => 'internal_wallet', 'status' => 1]);
            return is_array($g) ? (int) ($g['id'] ?? 0) : 0;
        } catch (\Throwable $e) {
            error_log('[gia] wallet_gateway_id: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('gia_pay_wallet_ready')) {
    /**
     * True iff this user can pay $amount in $currency from their wallet right now:
     * a verified user id, a configured wallet gateway, and sufficient balance.
     * Pure read — decides which path GIA offers; it does not charge.
     */
    function gia_pay_wallet_ready($db, string $userId, float $amount, string $currency): bool
    {
        $userId = trim($userId);
        if ($userId === '' || $amount <= 0) {
            return false;
        }
        if (!function_exists('wallet_balance')) {
            $w = dirname(__DIR__) . '/wallet.php';
            if (is_file($w)) { require_once $w; }
        }
        if (!function_exists('wallet_balance') || gia_pay_wallet_gateway_id($db) <= 0) {
            return false;
        }
        try {
            $bal = wallet_balance($db, $userId, $currency);
            return $bal + 0.0001 >= $amount; // tiny epsilon for float rounding
        } catch (\Throwable $e) {
            error_log('[gia] wallet_ready: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('gia_pay_assign_wallet_gateway')) {
    /**
     * Point a booking at the internal_wallet gateway so process_payment() debits
     * the wallet (through its own actor/currency guards). Returns true on success.
     * Only sets the gateway field — the actual debit is process_payment, invoked
     * by initiate_payment AFTER the approval gate. Refuses if already paid.
     */
    function gia_pay_assign_wallet_gateway($db, string $invoiceId): bool
    {
        $gwId = gia_pay_wallet_gateway_id($db);
        if ($gwId <= 0) {
            return false;
        }
        try {
            $b = $db->get('bookings', ['invoice_id', 'payment_status'], ['invoice_id' => $invoiceId]);
            if (!is_array($b) || empty($b['invoice_id'])) {
                return false;
            }
            if ((string) ($b['payment_status'] ?? '') === 'paid') {
                return false; // never re-point a paid booking
            }
            $db->update('bookings', ['payment_gateway' => $gwId], ['invoice_id' => $invoiceId]);
            return true;
        } catch (\Throwable $e) {
            error_log('[gia] assign_wallet_gateway: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('gia_pay_invoice_for_checkout')) {
    /**
     * Resolve the booking invoice_id created for a checkout_ref, via the draft hash:
     *   gia_checkouts.checkout_ref -> draft_hash -> bookings.booking_data(gia_draft_hash).
     * Returns '' if not found. Used by the approve-tap handler to point the booking
     * at the wallet gateway before payment.
     */
    function gia_pay_invoice_for_checkout($db, string $checkoutRef): string
    {
        $checkoutRef = trim($checkoutRef);
        if ($checkoutRef === '') {
            return '';
        }
        try {
            $co = $db->get('gia_checkouts', ['draft_hash'], ['checkout_ref' => $checkoutRef]);
            $hash = is_array($co) ? trim((string) ($co['draft_hash'] ?? '')) : '';
            if ($hash === '' || !preg_match('/^[a-f0-9]{8,64}$/i', $hash)) {
                return '';
            }
            $b = $db->get('bookings', ['invoice_id'], ['booking_data[~]' => '%"gia_draft_hash":"' . $hash . '"%']);
            return is_array($b) ? (string) ($b['invoice_id'] ?? '') : '';
        } catch (\Throwable $e) {
            error_log('[gia] invoice_for_checkout: ' . $e->getMessage());
            return '';
        }
    }
}

if (!function_exists('gia_pay_approve_and_charge')) {
    /**
     * DETERMINISTIC approve-&-pay for a verified channel tap. Given only a
     * checkout_ref (from the Approve button) + the verified user, it:
     *   1. recovers the draft_hash from gia_checkouts and rebuilds the live quote,
     *   2. mints a scoped approval (gia_request_approval) — the price-vs-snapshot
     *      gate still applies (PRICE_CHANGED if it drifted),
     *   3. charges via gia_commit_initiate_payment -> process_payment.
     * The money path does NOT depend on LLM memory of refs (stateless webhooks
     * don't replay tool results) — it is driven entirely from the signed checkout.
     *
     * Caller MUST have already confirmed the tap is a real Approve postback from a
     * VERIFIED chat (the spine does). Returns the commit result array
     * (['ok'=>, 'redirect_url'?=>, 'already_paid'?=>, 'error'?=>, ...]).
     */
    function gia_pay_approve_and_charge($db, string $checkoutRef, string $userId): array
    {
        $checkoutRef = trim($checkoutRef);
        if (!preg_match('/^gco_[a-f0-9]{20}$/', $checkoutRef)) {
            return ['ok' => false, 'error' => 'Invalid checkout.', 'error_code' => 'AI_BAD_CHECKOUT'];
        }
        require_once __DIR__ . '/gia_checkout.php';
        require_once __DIR__ . '/gia_commit.php';

        // 1) Recover the draft for this checkout + the booking invoice.
        try {
            $co = $db->get('gia_checkouts', ['draft_hash', 'user_id', 'status'], ['checkout_ref' => $checkoutRef]);
        } catch (\Throwable $e) {
            error_log('[gia] approve load checkout: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not load your checkout.', 'error_code' => 'AI_CHECKOUT_ERROR'];
        }
        $hash = is_array($co) ? trim((string) ($co['draft_hash'] ?? '')) : '';
        if ($hash === '' || !preg_match('/^[a-f0-9]{8,64}$/i', $hash)) {
            return ['ok' => false, 'error' => 'That checkout has expired — let me re-quote.', 'error_code' => 'AI_CHECKOUT_EXPIRED'];
        }
        // Ownership: if the checkout is bound to a user, it must be THIS user.
        $coUser = is_array($co) ? trim((string) ($co['user_id'] ?? '')) : '';
        if ($coUser !== '' && $userId !== '' && $coUser !== $userId) {
            return ['ok' => false, 'error' => 'This checkout belongs to another account.', 'error_code' => 'AI_CHECKOUT_OWNER'];
        }

        $invoiceId = gia_pay_invoice_for_checkout($db, $checkoutRef);
        if ($invoiceId === '') {
            return ['ok' => false, 'error' => 'No booking to pay — let me create it first.', 'error_code' => 'BOOKING_NOT_FOUND'];
        }

        // 2) Rebuild the live quote from the CURRENT draft (same shape the tools use).
        try {
            $draft = $db->get('logs_bookings', ['data', 'user_id'], ['hash' => $hash]);
        } catch (\Throwable $e) {
            error_log('[gia] approve load draft: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not load your trip draft.', 'error_code' => 'AI_DRAFT_ERROR'];
        }
        if (!$draft || empty($draft['data'])) {
            return ['ok' => false, 'error' => 'That trip draft has expired.', 'error_code' => 'AI_DRAFT_NOT_FOUND'];
        }
        $d = json_decode((string) $draft['data'], true);
        if (!is_array($d) || empty($d['items'])) {
            return ['ok' => false, 'error' => 'That draft has no trip items.', 'error_code' => 'AI_DRAFT_EMPTY'];
        }
        $liveQuote = [
            'currency' => (string) ($d['currency'] ?? 'USD'),
            'items'    => $d['items'],
            'total'    => (float) ($d['total'] ?? 0),
            'adults'   => (int) ($d['adults'] ?? ($d['pax']['adults'] ?? 0)),
            'children' => (int) ($d['children'] ?? ($d['pax']['children'] ?? 0)),
        ];
        $owner = $userId !== '' ? $userId : (string) ($draft['user_id'] ?? '');

        // 3) Mint the scoped approval (snapshot-gated), then charge.
        $ap = \gia_request_approval($db, $checkoutRef, $liveQuote, $owner !== '' ? $owner : null);
        if (empty($ap['ok']) || empty($ap['approval_token'])) {
            return [
                'ok' => false,
                'error' => (string) ($ap['error'] ?? 'Could not confirm approval.'),
                'error_code' => (string) ($ap['error_code'] ?? 'APPROVAL_FAILED'),
            ];
        }
        return \gia_commit_initiate_payment($db, $checkoutRef, (string) $ap['approval_token'], $invoiceId, $liveQuote, $owner !== '' ? $owner : null);
    }
}

if (!function_exists('gia_pay_link_sig')) {
    /** HMAC signature for a pay-link (invoice + expiry), using the GIA checkout secret. */
    function gia_pay_link_sig(string $invoiceId, int $expiry): string
    {
        if (!function_exists('gia_checkout_secret')) {
            $f = __DIR__ . '/gia_checkout.php';
            if (is_file($f)) { require_once $f; }
        }
        $secret = function_exists('gia_checkout_secret') ? gia_checkout_secret() : '';
        return hash_hmac('sha256', $invoiceId . '|' . $expiry, $secret);
    }
}

if (!function_exists('gia_pay_link')) {
    /**
     * A signed, expiring pay-link to the hosted /gia/pay/<invoice> page. Default
     * TTL 1 hour. The page re-verifies the signature + expiry before showing pay.
     */
    function gia_pay_link(string $invoiceId, int $ttlSeconds = 3600): string
    {
        $expiry = time() + max(300, $ttlSeconds);
        $sig = gia_pay_link_sig($invoiceId, $expiry);
        $base = defined('root') ? root : '/';
        return $base . 'gia/pay/' . rawurlencode($invoiceId) . '?exp=' . $expiry . '&sig=' . $sig;
    }
}

if (!function_exists('gia_pay_link_verify')) {
    /**
     * Verify a pay-link's signature + expiry (constant-time). Returns true iff the
     * link is intact and unexpired. The page calls this before rendering payment.
     */
    function gia_pay_link_verify(string $invoiceId, int $expiry, string $sig): bool
    {
        if ($expiry < time()) {
            return false;
        }
        $expected = gia_pay_link_sig($invoiceId, $expiry);
        return hash_equals($expected, $sig);
    }
}

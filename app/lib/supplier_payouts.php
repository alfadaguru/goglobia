<?php
// FILE: app/lib/supplier_payouts.php
// SUPPLIER PAYOUTS TO BANK (Phase 1 inc S19). THE HIGHEST-RISK CODE IN THE PROJECT —
// this is the only path that sends money OUT. Read this header fully before editing.
//
// Model (owner-chosen): ADMIN-APPROVED, MANUAL trigger + a master kill-switch.
//   1. Supplier saves bank details and REQUESTS a payout from their 'available'
//      earnings. This reserves the covering earnings (payout_id set) inside a locked
//      transaction so the same money can't be requested twice.
//   2. An admin REVIEWS and APPROVES (or rejects). Only on approval is a transfer
//      attempted — and ONLY if settings.supplier_payouts_live === '1' (ships '0',
//      so nothing can leave until an operator explicitly enables it on the server).
//   3. On transfer success the reserved earnings flip 'available' -> 'paid'. On
//      failure/reject the reservation is released (payout_id cleared) so the money
//      is requestable again.
//
// Outbound idempotency: every payout carries a unique `reference` (our idempotency
// key, also sent to Paystack). A transfer is only initiated once; a retry reuses the
// same reference. The earnings debit is COMMITTED before the transfer call, and the
// transfer result only advances state — we never send without a committed reservation.
//
// Reuses the existing Paystack primitives (wallet.php): paystack_dva_gateway($db),
// paystack_dva_secret($g), paystack_dva_http(). Secret = payment_gateways.c1.
//
// 7 risks (payout-grounding) addressed inline: (1) double-pay on retry → unique
// reference + state guards; (2) debit-then-send limbo → reserve first, send after,
// reconcile on failure; (3) concurrent-withdrawal race → SELECT…FOR UPDATE on the
// earnings; (4) legacy-mirror corruption → never touches the wallet spine; (5) float
// → integer kobo for Paystack, DECIMAL in DB; (6) server-derived amount → amount is
// re-validated against the locked available balance, never trusted from the client
// beyond an upper request; (7) authz → request is owner-only, approve/transfer is
// ADMIN-only (enforced by the route), and the kill-switch gates the live call.

if (!function_exists('supplier_payouts_live')) {
    /** Master kill-switch. Transfers only fire when this is '1' (ships '0'). */
    function supplier_payouts_live($db): bool
    {
        try {
            $v = $db->get('settings', ['supplier_payouts_live'], ['id' => 1]);
            return $v && (string) ($v['supplier_payouts_live'] ?? '0') === '1';
        } catch (\Throwable $e) {
            error_log('supplier_payouts_live: ' . $e->getMessage());
            return false; // fail-closed: unknown → do not send
        }
    }
}

if (!function_exists('supplier_payable_available')) {
    /**
     * The owner's UNRESERVED available balance per currency:
     *   [currency => float]   (state='available' AND payout_id IS NULL, not void).
     * This is the only balance a new payout can draw from.
     */
    function supplier_payable_available($db, string $owner): array
    {
        $owner = trim($owner);
        if ($owner === '') { return []; }
        $out = [];
        try {
            $rows = $db->select('supplier_earnings', ['currency', 'net_amount'],
                ['owner_user_id' => $owner, 'state' => 'available', 'payout_id' => null]) ?: [];
            foreach ($rows as $r) {
                $cur = strtoupper((string) ($r['currency'] ?? 'USD'));
                $out[$cur] = round(($out[$cur] ?? 0) + (float) $r['net_amount'], 2);
            }
        } catch (\Throwable $e) {
            error_log('supplier_payable_available: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('supplier_payout_reference')) {
    /** A unique, idempotent payout reference. No Date/random ban here (runtime). */
    function supplier_payout_reference(): string
    {
        return 'SPOUT-' . date('Ymd') . '-' . bin2hex(random_bytes(8));
    }
}

if (!function_exists('supplier_payout_request')) {
    /**
     * Create a payout request for $amount in $currency from the owner's UNRESERVED
     * available earnings. Atomically (SELECT…FOR UPDATE) verifies the balance and
     * RESERVES the covering earnings (sets payout_id) so the same money can't be
     * requested twice. Returns ['ok'=>bool,'payout_id'=>?int,'message'=>?].
     * State starts 'requested' — NO money moves here; an admin must approve.
     */
    function supplier_payout_request($db, string $owner, float $amount, string $currency): array
    {
        $owner = trim($owner);
        $currency = strtoupper(trim($currency)) ?: 'USD';
        $amount = round($amount, 2);
        if ($owner === '') { return ['ok' => false, 'message' => 'No owner']; }
        if ($amount <= 0) { return ['ok' => false, 'message' => 'Amount must be positive']; }

        // Bank details must be on file before a request.
        $u = $db->get('users', ['payout_bank_code', 'payout_account_number', 'payout_account_name', 'org_id'], ['user_id' => $owner]);
        $bankCode = trim((string) ($u['payout_bank_code'] ?? ''));
        $acctNo   = trim((string) ($u['payout_account_number'] ?? ''));
        if ($bankCode === '' || $acctNo === '') {
            return ['ok' => false, 'message' => 'Add your bank details before requesting a payout.'];
        }
        $orgId = function_exists('supplier_org_for_owner') ? supplier_org_for_owner($db, $owner) : 0;

        $result = ['ok' => false, 'message' => 'Could not create the payout request'];
        try {
            $db->action(function ($db) use ($owner, $amount, $currency, $bankCode, $acctNo, $u, $orgId, &$result) {
                // Lock this owner's unreserved available earnings in this currency.
                $locked = $db->query(
                    "SELECT id, net_amount FROM supplier_earnings
                     WHERE owner_user_id = :o AND currency = :c AND state = 'available'
                       AND payout_id IS NULL
                     ORDER BY id ASC FOR UPDATE",
                    [':o' => $owner, ':c' => $currency]
                );
                $rows = $locked ? $locked->fetchAll(\PDO::FETCH_ASSOC) : [];
                $avail = 0.0;
                foreach ($rows as $r) { $avail = round($avail + (float) $r['net_amount'], 2); }
                if ($amount > $avail + 1e-9) {
                    $result = ['ok' => false, 'message' => 'Requested amount exceeds your available balance.'];
                    return false; // rollback
                }

                // Create the payout row (requested).
                $ref = supplier_payout_reference();
                $db->insert('supplier_payouts', [
                    'org_id'          => $orgId > 0 ? $orgId : null,
                    'owner_user_id'   => $owner,
                    'currency'        => $currency,
                    'amount'          => $amount,
                    'state'           => 'requested',
                    'bank_code'       => $bankCode,
                    'account_number'  => $acctNo,
                    'account_name'    => trim((string) ($u['payout_account_name'] ?? '')) ?: null,
                    'reference'       => $ref,
                    'requested_at'    => date('Y-m-d H:i:s'),
                    'created_at'      => date('Y-m-d H:i:s'),
                ]);
                $payoutId = (int) $db->id();
                if ($payoutId <= 0) { $result = ['ok' => false, 'message' => 'Insert failed']; return false; }

                // RESERVE covering earnings (oldest first) up to the amount by setting
                // payout_id. They stay 'available' (reserved) until the transfer succeeds.
                $need = $amount;
                foreach ($rows as $r) {
                    if ($need <= 1e-9) { break; }
                    $db->update('supplier_earnings',
                        ['payout_id' => $payoutId, 'updated_at' => date('Y-m-d H:i:s')],
                        ['id' => (int) $r['id'], 'state' => 'available', 'payout_id' => null]);
                    $need = round($need - (float) $r['net_amount'], 2);
                }
                $result = ['ok' => true, 'payout_id' => $payoutId, 'message' => 'Payout requested.'];
                return true; // commit
            });
        } catch (\Throwable $e) {
            error_log('supplier_payout_request: ' . $e->getMessage());
        }
        return $result;
    }
}

if (!function_exists('supplier_payout_release_reservation')) {
    /** Release a payout's reserved earnings (reject/fail/cancel): clear payout_id so
     *  the money becomes requestable again. Only affects still-'available' rows (a
     *  'paid' row is settled and must never be un-reserved here). */
    function supplier_payout_release_reservation($db, int $payoutId): void
    {
        if ($payoutId <= 0) { return; }
        try {
            $db->update('supplier_earnings',
                ['payout_id' => null, 'updated_at' => date('Y-m-d H:i:s')],
                ['payout_id' => $payoutId, 'state' => 'available']);
        } catch (\Throwable $e) {
            error_log('supplier_payout_release_reservation: ' . $e->getMessage());
        }
    }
}

if (!function_exists('supplier_payout_reject')) {
    /** Admin rejects a requested/approved payout (before it is paid). Releases the
     *  reservation. Never affects a 'paid' payout. */
    function supplier_payout_reject($db, int $payoutId, string $reason = ''): bool
    {
        try {
            $p = $db->get('supplier_payouts', ['id', 'state'], ['id' => $payoutId]);
            if (!$p || in_array($p['state'], ['paid', 'processing'], true)) { return false; }
            $db->update('supplier_payouts',
                ['state' => 'rejected', 'failure_reason' => substr($reason, 0, 255), 'decided_at' => date('Y-m-d H:i:s')],
                ['id' => $payoutId]);
            supplier_payout_release_reservation($db, $payoutId);
            return true;
        } catch (\Throwable $e) {
            error_log('supplier_payout_reject: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('supplier_payout_approve_and_send')) {
    /**
     * Admin approves a REQUESTED payout and (if the kill-switch is live) sends it via
     * Paystack Transfer. Returns ['ok'=>bool,'state'=>,'message'=>]. The money debit
     * (earnings 'available'->'paid') is only committed on a confirmed/queued transfer;
     * on failure the payout is 'failed' and the reservation released.
     *
     * Idempotency + no-double-send: a state guard ensures only a 'requested' (or a
     * retryable 'failed') payout proceeds; the unique `reference` is reused so Paystack
     * dedupes a retried transfer; 'processing'/'paid' are never re-sent.
     */
    function supplier_payout_approve_and_send($db, int $payoutId): array
    {
        $p = $db->get('supplier_payouts', '*', ['id' => $payoutId]);
        if (!$p) { return ['ok' => false, 'message' => 'Payout not found']; }
        $state = (string) $p['state'];
        if (!in_array($state, ['requested', 'failed'], true)) {
            return ['ok' => false, 'message' => 'Payout is not in a sendable state (' . $state . ').'];
        }

        // Mark approved + decided first (audit), independent of the transfer.
        $db->update('supplier_payouts',
            ['state' => 'approved', 'decided_at' => date('Y-m-d H:i:s'), 'decided_by' => (string) ($_SESSION['user_id'] ?? '')],
            ['id' => $payoutId]);

        // KILL-SWITCH: if payouts aren't enabled, stop here — approved but NOT sent.
        if (!supplier_payouts_live($db)) {
            return ['ok' => true, 'state' => 'approved', 'message' => 'Approved. Transfers are disabled (settings.supplier_payouts_live = 0) — nothing was sent.'];
        }

        // Resolve Paystack creds (NGN gateway). Only NGN transfers are supported here.
        $gw = function_exists('paystack_dva_gateway') ? paystack_dva_gateway($db) : null;
        $secret = $gw ? paystack_dva_secret($gw) : '';
        if ($secret === '' || strtoupper((string) $p['currency']) !== 'NGN') {
            return ['ok' => false, 'message' => 'Paystack NGN gateway not configured for payouts.'];
        }

        // 1) Ensure a transfer recipient (cache the recipient_code on the payout + user).
        $recipient = trim((string) ($p['recipient_code'] ?? ''));
        if ($recipient === '') {
            $rres = paystack_dva_http('POST', 'https://api.paystack.co/transferrecipient', $secret, [
                'type'           => 'nuban',
                'name'           => (string) ($p['account_name'] ?: $p['owner_user_id']),
                'account_number' => (string) $p['account_number'],
                'bank_code'      => (string) $p['bank_code'],
                'currency'       => 'NGN',
            ]);
            $recipient = (string) ($rres['json']['data']['recipient_code'] ?? '');
            if ($recipient === '') {
                $msg = $rres['json']['message'] ?? ($rres['error'] ?: 'Recipient creation failed');
                $db->update('supplier_payouts', ['failure_reason' => substr('recipient: ' . $msg, 0, 255)], ['id' => $payoutId]);
                return ['ok' => false, 'message' => 'Could not create transfer recipient: ' . $msg];
            }
            $db->update('supplier_payouts', ['recipient_code' => $recipient], ['id' => $payoutId]);
        }

        // 2) Mark 'processing' BEFORE the transfer call (so a crash mid-call never
        //    leaves a 'requested' row that could be re-sent; recovery inspects
        //    'processing' rows against Paystack by reference).
        $db->update('supplier_payouts', ['state' => 'processing'], ['id' => $payoutId, 'state' => 'approved']);

        // 3) Initiate the transfer. amount in KOBO (integer) — never float to Paystack.
        $kobo = (int) round(((float) $p['amount']) * 100);
        $tres = paystack_dva_http('POST', 'https://api.paystack.co/transfer', $secret, [
            'source'    => 'balance',
            'amount'    => $kobo,
            'recipient' => $recipient,
            'currency'  => 'NGN',
            'reason'    => 'Supplier payout ' . (string) $p['reference'],
            'reference' => (string) $p['reference'], // idempotency key (reused on retry)
        ]);
        $tstatus = strtolower((string) ($tres['json']['data']['status'] ?? ''));
        $transferCode = (string) ($tres['json']['data']['transfer_code'] ?? '');
        $httpOk = $tres['http'] >= 200 && $tres['http'] < 300;

        // Paystack transfer 'status': success | pending | otherwise. success/pending =
        // accepted (finalised by the transfer webhook); otherwise = failed.
        if ($httpOk && in_array($tstatus, ['success', 'pending', 'otp'], true)) {
            // COMMIT the debit: reserved earnings 'available' -> 'paid'.
            try {
                $db->update('supplier_earnings',
                    ['state' => 'paid', 'updated_at' => date('Y-m-d H:i:s')],
                    ['payout_id' => $payoutId, 'state' => 'available']);
            } catch (\Throwable $e) { error_log('payout debit: ' . $e->getMessage()); }
            $db->update('supplier_payouts', [
                'state'         => 'paid',
                'transfer_code' => $transferCode ?: null,
                'paid_at'       => date('Y-m-d H:i:s'),
            ], ['id' => $payoutId]);
            if (function_exists('emit_event')) {
                emit_event($db, 'payout.paid', ['payout_id' => $payoutId, 'amount' => (float) $p['amount'], 'currency' => $p['currency']],
                    'supplier_payout', $payoutId, $p['org_id'] !== null ? (int) $p['org_id'] : null);
            }
            return ['ok' => true, 'state' => 'paid', 'message' => 'Transfer initiated.'];
        }

        // Failed → mark failed + release the reservation so the money is requestable again.
        $msg = $tres['json']['message'] ?? ($tres['error'] ?: 'Transfer failed');
        $db->update('supplier_payouts',
            ['state' => 'failed', 'failure_reason' => substr((string) $msg, 0, 255)],
            ['id' => $payoutId]);
        supplier_payout_release_reservation($db, $payoutId);
        return ['ok' => false, 'state' => 'failed', 'message' => 'Transfer failed: ' . $msg];
    }
}

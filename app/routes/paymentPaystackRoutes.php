<?php
// ============================================================================
// PAYSTACK WEBHOOK — Dedicated Virtual Account (DVA / NUBAN) deposits
// ----------------------------------------------------------------------------
// Public, unauthenticated endpoint that receives Paystack's asynchronous events.
// This is the ONLY way money paid into a customer's dedicated virtual account
// reaches us — there is no synchronous redirect for a bank transfer.
//
// Every request is verified with the X-Paystack-Signature header: an
// HMAC-SHA512 of the RAW request body, keyed with the gateway SECRET key (c1).
// A request whose signature does not match is ignored (401) and NOT trusted.
//
// On a `charge.success` event whose channel is `dedicated_nuban` (a transfer
// into a virtual account), we resolve the owning user by the Paystack customer
// code and credit their wallet through the SPINE — idempotent on the Paystack
// reference (money_transactions.idempotency_key is UNIQUE), so Paystack's
// retries never double-credit.
//
// Register this URL in the Paystack Dashboard (Settings > API Keys & Webhooks):
//       https://<your-domain>/payment/paystack/webhook
// ============================================================================

@$SECURE or die('Access Denied!');

$router->post('payment/paystack/webhook', function () use ($SECURE, $db) {

    require_once 'app/lib/wallet.php';

    // Always read the RAW body first — the signature is computed over these exact
    // bytes, so json_decode()+re-encode would break verification.
    $raw = file_get_contents('php://input');

    // Acknowledge helper. Paystack retries on non-2xx; once we have understood a
    // request (valid signature, whether or not it was an event we act on) we
    // return 200 so it stops retrying. Bad signature → 401 (do not trust).
    $ack = function (int $code = 200, string $body = 'OK') {
        http_response_code($code);
        header('Content-Type: text/plain');
        echo $body;
        exit;
    };

    if ($raw === '' || $raw === false) {
        error_log('PAYSTACK WEBHOOK | empty body');
        $ack(200, 'ignored'); // nothing to verify; don't make Paystack retry forever
    }

    // Resolve the secret key from the enabled Paystack gateway (c1).
    $gateway = function_exists('paystack_dva_gateway') ? paystack_dva_gateway($db) : null;
    $secret  = $gateway ? paystack_dva_secret($gateway) : '';
    if ($secret === '') {
        error_log('PAYSTACK WEBHOOK | no Paystack secret configured — cannot verify signature');
        $ack(200, 'unconfigured');
    }

    // ---- Signature verification (HMAC-SHA512 of the raw body). --------------
    $sig = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
    $expected = hash_hmac('sha512', $raw, $secret);
    if ($sig === '' || !hash_equals($expected, $sig)) {
        error_log('PAYSTACK WEBHOOK | invalid signature');
        $ack(401, 'invalid signature');
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload) || empty($payload['event'])) {
        error_log('PAYSTACK WEBHOOK | verified but unparseable payload');
        $ack(200, 'ignored');
    }

    $event = (string) $payload['event'];
    $data  = is_array($payload['data'] ?? null) ? $payload['data'] : [];

    // SUPPLIER PAYOUT TRANSFERS: Paystack posts ALL events to the one configured URL,
    // so the existing (already-registered) webhook also handles transfer outcomes — no
    // second URL to register. transfer.success/failed/reversed finalise a supplier payout
    // by reference (idempotent + atomic via supplier_payout_finalize_transfer). This runs
    // BEFORE the charge.success guard below so transfer events aren't dropped.
    $transferMap = ['transfer.success' => 'success', 'transfer.failed' => 'failed', 'transfer.reversed' => 'reversed'];
    if (isset($transferMap[$event])) {
        $reference    = (string) ($data['reference'] ?? '');
        $transferCode = (string) ($data['transfer_code'] ?? '');
        if ($reference === '') { $ack(200, 'ignored'); } // our payouts always carry a reference
        $payout = $db->get('supplier_payouts', ['id'], ['reference' => $reference]);
        if (!$payout && $transferCode !== '') {
            $payout = $db->get('supplier_payouts', ['id'], ['transfer_code' => $transferCode]);
        }
        if ($payout && function_exists('supplier_payout_finalize_transfer')) {
            $note = (string) ($data['gateway_response'] ?? ($data['reason'] ?? ''));
            $r = supplier_payout_finalize_transfer($db, (int) $payout['id'], $transferMap[$event], $transferCode, $note);
            error_log('PAYSTACK WEBHOOK | ' . $event . ' payout#' . (int) $payout['id'] . ' -> ' . ($r['message'] ?? '?'));
        } else {
            error_log('PAYSTACK WEBHOOK | ' . $event . ' no payout for reference=' . $reference);
        }
        $ack(200, 'OK'); // handled (or not ours) — don't fall through to charge logic
    }

    // We only act on a successful charge that funded a dedicated virtual account.
    if ($event !== 'charge.success') {
        $ack(200, 'ignored'); // e.g. dedicatedaccount.assign.* — nothing to credit
    }

    $status  = strtolower((string) ($data['status'] ?? ''));
    $channel = strtolower((string) ($data['channel'] ?? ''));
    // Paystack marks DVA transfers with channel 'dedicated_nuban'. Guard on it so
    // a normal card charge (already handled by the synchronous callback) is not
    // double-credited here.
    if ($status !== 'success' || $channel !== 'dedicated_nuban') {
        $ack(200, 'ignored');
    }

    // Resolve the owning user by Paystack customer code.
    $customerCode = (string) ($data['customer']['customer_code'] ?? '');
    if ($customerCode === '') {
        error_log('PAYSTACK WEBHOOK | charge.success with no customer_code');
        $ack(200, 'ignored');
    }
    $user = $db->get('users', ['user_id', 'currency'], ['paystack_customer_code' => $customerCode]);
    if (!$user) {
        error_log('PAYSTACK WEBHOOK | no user for customer_code ' . $customerCode);
        $ack(200, 'ignored'); // not one of ours (or account created out-of-band)
    }

    // Amount: Paystack sends kobo (minor units); DVA on our platform is NGN.
    $amountMinor = (int) ($data['amount'] ?? 0);
    $amount      = round($amountMinor / 100, 2);
    $currency    = strtoupper((string) ($data['currency'] ?? 'NGN')) ?: 'NGN';
    $reference   = (string) ($data['reference'] ?? '');
    if ($amount <= 0 || $reference === '') {
        error_log('PAYSTACK WEBHOOK | charge.success with no amount/reference');
        $ack(200, 'ignored');
    }

    // ---- Credit the wallet through the SPINE, idempotent on the reference. ---
    // idempotency_key is UNIQUE, so a retried webhook returns the existing txn
    // and never double-credits.
    $idem = 'PSKDVA-' . $reference;
    $existing = $db->get('money_transactions', ['id', 'status'], ['idempotency_key' => $idem]);
    if ($existing && $existing['status'] === 'success') {
        $ack(200, 'already'); // seen this deposit before — done
    }

    try {
        $txn = txn_create($db, [
            'user_id'         => (string) $user['user_id'],
            'direction'       => 'credit',
            'reason'          => 'wallet_topup',
            'amount'          => $amount,
            'currency'        => $currency,
            'method'          => 'gateway',
            'gateway_id'      => $gateway['id'] ?? null,
            'idempotency_key' => $idem,
            'description'     => 'Virtual account deposit ' . $reference,
        ]);
        // RETRYABLE FAILURES (audit #31): a credit failure must NOT be ack'd 200 — that
        // tells Paystack "processed, stop retrying" and permanently strands the
        // customer's deposit uncredited. Return a non-2xx so Paystack re-delivers until
        // the credit succeeds. Idempotency (PSKDVA-{reference}) makes replays safe: a
        // retry that eventually succeeds credits exactly once.
        if (!$txn || empty($txn['id'])) {
            error_log('PAYSTACK WEBHOOK | could not create txn for ' . $idem . ' — returning 500 for retry');
            $ack(500, 'retry: could not record deposit');
        }
        // If txn_create returned an already-successful row (idempotent), stop.
        if (($txn['status'] ?? 'pending') === 'success') {
            $ack(200, 'already');
        }
        $res = wallet_topup_success($db, (int) $txn['id'], $reference, [
            'gateway' => 'paystack', 'channel' => 'dedicated_nuban', 'reference' => $reference,
        ]);
        if (empty($res['ok'])) {
            error_log('PAYSTACK WEBHOOK | credit failed for ' . $idem . ': ' . ($res['message'] ?? '?') . ' — returning 500 for retry');
            $ack(500, 'retry: credit failed');
        }
    } catch (\Throwable $e) {
        error_log('PAYSTACK WEBHOOK | exception crediting ' . $idem . ': ' . $e->getMessage() . ' — returning 500 for retry');
        $ack(500, 'retry: exception');
    }

    $ack(200, 'OK');
});

// ============================================================================
// PAYSTACK WEBHOOK — Transfers (SUPPLIER PAYOUTS, outbound money)
// ----------------------------------------------------------------------------
// Authoritative final status for a supplier payout transfer. approve_and_send
// optimistically marks a payout 'paid' on the synchronous response, but Paystack's
// real outcome arrives here: transfer.success / transfer.failed / transfer.reversed.
// We verify the signature exactly as above (HMAC-SHA512 of the raw body, keyed with
// the gateway secret c1), match the payout by our `reference` (the transfer's
// idempotency key we sent), and finalise idempotently via
// supplier_payout_finalize_transfer(). A reversal after an optimistic 'paid' returns
// the earnings to 'available' so money is never lost.
//
// Register in the Paystack Dashboard (same place as the charge webhook; Paystack
// posts all events to the configured URL, so pointing transfers here too is fine):
//       https://<your-domain>/payment/paystack/transfer-webhook
// (You may also use the single charge webhook URL — this separate route exists so
//  transfer events are handled even if the two URLs are configured independently.)
// ============================================================================
$router->post('payment/paystack/transfer-webhook', function () use ($SECURE, $db) {

    require_once 'app/lib/wallet.php';

    $raw = file_get_contents('php://input');
    $ack = function (int $code = 200, string $body = 'OK') {
        http_response_code($code);
        header('Content-Type: text/plain');
        echo $body;
        exit;
    };
    if ($raw === '' || $raw === false) { $ack(200, 'ignored'); }

    $gateway = function_exists('paystack_dva_gateway') ? paystack_dva_gateway($db) : null;
    $secret  = $gateway ? paystack_dva_secret($gateway) : '';
    if ($secret === '') { error_log('PAYSTACK TRANSFER WH | no secret'); $ack(200, 'unconfigured'); }

    $sig = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
    $expected = hash_hmac('sha512', $raw, $secret);
    if ($sig === '' || !hash_equals($expected, $sig)) {
        error_log('PAYSTACK TRANSFER WH | invalid signature');
        $ack(401, 'invalid signature');
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload) || empty($payload['event'])) { $ack(200, 'ignored'); }

    $event = (string) $payload['event'];
    $data  = is_array($payload['data'] ?? null) ? $payload['data'] : [];

    // Only transfer events concern supplier payouts.
    $map = ['transfer.success' => 'success', 'transfer.failed' => 'failed', 'transfer.reversed' => 'reversed'];
    if (!isset($map[$event])) { $ack(200, 'ignored'); } // e.g. charge.success handled by the other route

    $reference    = (string) ($data['reference'] ?? '');
    $transferCode = (string) ($data['transfer_code'] ?? '');
    // Our outbound transfers ALWAYS carry our unique reference (the UNIQUE idempotency
    // key). Match strictly on it — never fall back to transfer_code alone, which is not
    // unique in our schema and could finalise the wrong payout.
    if ($reference === '') {
        error_log('PAYSTACK TRANSFER WH | ' . $event . ' with no reference');
        $ack(200, 'ignored');
    }
    $payout = $db->get('supplier_payouts', ['id'], ['reference' => $reference]);
    if (!$payout) {
        error_log('PAYSTACK TRANSFER WH | no payout for reference=' . $reference);
        $ack(200, 'ignored'); // not one of ours (or a transfer made outside this system)
    }

    if (!function_exists('supplier_payout_finalize_transfer')) { $ack(200, 'unavailable'); }
    $note = (string) ($data['gateway_response'] ?? ($data['reason'] ?? ''));
    $res = supplier_payout_finalize_transfer($db, (int) $payout['id'], $map[$event], $transferCode, $note);
    error_log('PAYSTACK TRANSFER WH | ' . $event . ' payout#' . (int) $payout['id'] . ' -> ' . ($res['message'] ?? '?'));
    $ack(200, 'OK');
});

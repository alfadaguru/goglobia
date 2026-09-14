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
        if (!$txn || empty($txn['id'])) {
            error_log('PAYSTACK WEBHOOK | could not create txn for ' . $idem);
            $ack(200, 'error');
        }
        // If txn_create returned an already-successful row (idempotent), stop.
        if (($txn['status'] ?? 'pending') === 'success') {
            $ack(200, 'already');
        }
        $res = wallet_topup_success($db, (int) $txn['id'], $reference, [
            'gateway' => 'paystack', 'channel' => 'dedicated_nuban', 'reference' => $reference,
        ]);
        if (empty($res['ok'])) {
            error_log('PAYSTACK WEBHOOK | credit failed for ' . $idem . ': ' . ($res['message'] ?? '?'));
            $ack(200, 'error');
        }
    } catch (\Throwable $e) {
        error_log('PAYSTACK WEBHOOK | exception crediting ' . $idem . ': ' . $e->getMessage());
        $ack(200, 'error');
    }

    $ack(200, 'OK');
});

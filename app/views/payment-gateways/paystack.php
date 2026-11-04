<?php

/* paystack test credentials
Test Mode
Card: 4084084084084081
CVV: 408 | PIN: 0000 | OTP: 123456
*/

if (!isset($_POST['payload']) && !isset($paymentData)) return;

// Extract payment data
if (isset($_POST['payload'])) {
    $payload = json_decode(base64_decode($_POST['payload']));
    if (!$payload) { echo '<p style="color:red">Invalid payment payload.</p>'; return; }

    $booking = [
        'invoice_id' => $payload->booking_ref_no ?? $payload->invoice_id,
        'email' => $payload->client_email,
        'price_markup' => $payload->price,
        'currency_markup' => $payload->currency
    ];

    $token = $_POST['payment_token'] ?? $_POST['payload'];
    $tokenParam = urlencode($token ?? '');
    $successUrl = $_POST['success_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . $tokenParam . '&payment_status=success');
    $cancelUrl = $_POST['cancel_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . $tokenParam . '&payment_status=cancel');

    if (isset($gateway) && is_array($gateway)) {
        $config = get_gateway_config($gateway);
    } else {
        global $db;
        $gateway = $db->get('payment_gateways', '*', ['name' => 'Paystack', 'status' => 1]);
        $config = get_gateway_config($gateway ?: []);
    }
} else {
    $booking = $paymentData['booking'];
    $gateway = $paymentData['gateway'];
    $successUrl = $paymentData['success_url'];
    $cancelUrl = $paymentData['cancel_url'];
    $config = get_gateway_config($gateway);
}

// ============================================================================
// IDEMPOTENCY GUARD (customer-journey audit #3): never start a SECOND real
// charge for an invoice that is already paid. The GET gateway-loader routes
// (/payment/gateway/{hash}, /payment/{hash}) include this view directly and
// bypass process_payment()'s already-paid guard, so a browser-back / stale-tab
// / re-visited-hash replay would otherwise create a duplicate Paystack charge
// (the callback idempotency guard then KEEPS the second charge with no refund).
// Mirrors wallet_balance.php / credits.php. Re-read status from the DB because
// the synthetic $booking above carries no payment_status.
// ============================================================================
if (!empty($booking['invoice_id'])) {
    global $db;
    $freshPayStatus = $db->get('bookings', 'payment_status', ['invoice_id' => $booking['invoice_id']]);
    if (($freshPayStatus ?? '') === 'paid') {
        echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px;border:1px solid #bbf7d0;">';
        echo '<h4 style="color:#16a34a;margin-top:0;">Already Paid</h4>';
        echo '<p style="color:#166534;margin-bottom:10px;">This invoice has already been paid — no further charge was made.</p>';
        echo '<p style="margin-top:15px;"><a href="' . htmlspecialchars(root . 'invoice/' . $booking['invoice_id']) . '" style="color:#2563eb;">← Return to Invoice</a></p>';
        echo '</div>';
        return;
    }
}

// Try multiple possible field names for secret key
$secretKey = $config['c1'] ?? $gateway['c1'] ?? $config['secret_key'] ?? $gateway['secret_key'] ?? '';
if (empty($secretKey)) {
    // Audit: do NOT dump config/gateway key names to the browser (recon leak).
    echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px">';
    echo '<p style="color:red">Paystack is not configured. Please contact support.</p>';
    echo '</div>';
    error_log('Paystack: secret key (c1) not configured for gateway ' . ($gateway['id'] ?? '?'));
    return;
}

// Amount MUST be an INTEGER in the currency subunit (kobo). Float *100 can yield
// 10009.9999… and json_encode a non-integer Paystack rejects — round to int (audit).
$amountInKobo = (int) round((float)$booking['price_markup'] * 100);
$amount = number_format((float)$booking['price_markup'], 2, '.', '');
$currency = strtoupper($booking['currency_markup'] ?? 'NGN');

// Paystack supported currencies: NGN, GHS, ZAR, KES, USD (if enabled)
// If USD is not enabled on your account, fallback to NGN
$supportedCurrencies = ['NGN', 'GHS', 'ZAR', 'KES', 'USD'];
if (!in_array($currency, $supportedCurrencies)) {
    $currency = 'NGN'; // Default fallback
}

// Override currency if specified in gateway config
if (!empty($config['currency'])) {
    $currency = strtoupper($config['currency']);
}

// A VALID email is REQUIRED by Paystack's /transaction/initialize — an empty or
// malformed address returns 400 "email must be a valid email" and the gateway
// never launches (observed as "gateway could not launch" on checkout). The old
// `?? 'customer@example.com'` only caught a NULL, not the empty string the payload
// path produces, so a booking with a blank email failed. Use a real fallback that
// triggers on empty/invalid too, so the gateway always launches.
$email = trim((string) ($booking['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $__fallbackDomain = preg_replace('/^https?:\/\//', '', rtrim((string) root, '/'));
    $__fallbackDomain = preg_replace('/[^a-z0-9.\-]/i', '', $__fallbackDomain) ?: 'example.com';
    if (strpos($__fallbackDomain, '.') === false) { $__fallbackDomain .= '.com'; }
    $email = 'noreply+' . preg_replace('/[^a-z0-9]/i', '', (string) ($booking['invoice_id'] ?? 'guest')) . '@' . $__fallbackDomain;
}
$reference = 'PSK-' . $booking['invoice_id'] . '-' . time();

// Build callback URL - includes payment_status=success to trigger invoice route handler.
// Paystack only supports a single callback URL for all outcomes.
// Server-side verification in handle_payment_callback() will override this
// if the payment was actually cancelled/failed.
$tokenParam = urlencode($paymentData['token'] ?? $token ?? '');
$invoiceBase = root . 'invoice/' . $booking['invoice_id'];
$callbackUrl = $invoiceBase . '?token=' . $tokenParam . '&payment_status=success&gateway=paystack&trxref=' . $reference . '&transaction_id=' . $reference;

// Initialize Paystack transaction
try {
$url = "https://api.paystack.co/transaction/initialize";
$fields = [
    'email' => $email,
    'amount' => $amountInKobo,
    'currency' => $currency,
    'reference' => $reference,
    'callback_url' => $callbackUrl
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$secretKey}",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// ROOT-CAUSE FIX (payment "keeps loading forever"): this view is include()d by
// GET /payment/gateway/{hash} and the customer's pay page spins on the fetch while
// this cURL runs. Without a timeout, a slow/unreachable Paystack (common on a
// local box, flaky network, or bad key) made curl_exec block INDEFINITELY → the
// pay page loaded forever. Every other gateway view sets a timeout (cashfree/mpesa/
// xmoney =30, coinsbuy =45); paystack.php was the only one without. Bound it so the
// `if ($curlError)` branch below renders a clean error + Return-to-Invoice instead.
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
if (function_exists('paylog')) { paylog('paystack:BEFORE curl_exec -> ' . $url, ['invoice_id' => $reference ?? ($booking['invoice_id'] ?? '?'), 'amount_kobo' => $amountInKobo ?? '?']); }
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
if (function_exists('paylog')) { paylog('paystack:AFTER curl_exec', ['http' => $httpCode, 'curl_err' => $curlError ?: 'none', 'resp_len' => is_string($response) ? strlen($response) : 0]); }

$result = json_decode($response, true);

if ($curlError) {
    echo '<p style="color:red">Connection error: ' . htmlspecialchars($curlError) . '</p>';
    return;
}

if ($result && !empty($result['status']) && isset($result['data']['access_code'])) {
    $accessCode = (string) $result['data']['access_code'];
    $authUrl    = (string) ($result['data']['authorization_url'] ?? '');
} else {
    $errorMsg = $result['message'] ?? 'Unknown error';
    if (function_exists('paylog')) { paylog('paystack:INIT-FAILED', ['http' => $httpCode, 'msg' => $errorMsg]); }
    echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px">';
    echo '<p style="color:red"><strong>Payment initialization failed:</strong><br>' . htmlspecialchars($errorMsg) . '</p>';
    echo '<p style="font-size:12px;color:#666">HTTP Code: ' . $httpCode . '</p>';
    if (!empty($config['dev_mode'])) {
        echo '<pre style="background:#f5f5f5;padding:10px;font-size:11px;overflow:auto">' . htmlspecialchars((string) $response) . '</pre>';
    }
    echo '</div>';
    return;
}
} catch (\Throwable $e) {
    error_log("PAYSTACK_PAYMENT ERROR: " . $e->getMessage());
    echo '<p style="color:red">Payment processing error. Please try again.</p>';
    return;
}

// Public key (c2 = pk_...) for Paystack Inline. Not strictly required by
// resumeTransaction(access_code), but passed for robustness.
$publicKey = trim((string) ($config['c2'] ?? $gateway['c2'] ?? $config['public_key'] ?? ''));
if (function_exists('paylog')) { paylog('paystack:INIT-OK -> popup resumeTransaction', ['invoice_id' => $booking['invoice_id'] ?? '?', 'has_access_code' => $accessCode !== '']); }
?>

<!-- ============================================================================
 PAYSTACK POPUP (InlineJS v2) — the CORRECT method for our AJAX-loaded gateway view.
 This view is fetch()'d into a <div> (pay_view.php), so a server header(Location)
 or a top-level redirect cannot navigate the customer's window (root cause of the
 "keeps loading / never launches" bug). Instead: the SERVER already did Initialize
 (secret key stayed server-side) and we hand the resulting ACCESS_CODE to the
 browser, load Paystack InlineJS v2, and call new Paystack().resumeTransaction(code)
 — checkout opens as an in-page modal with NO navigation. onSuccess navigates to our
 existing Verify callback (handle_payment_callback re-verifies with /transaction/verify
 — "always verify before giving value"). onCancel/onError reset the UI cleanly.
 (Per Paystack docs: Accept Payments + InlineJS v2.)
============================================================================ -->
<style>
.paystack-container{max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px;box-shadow:0 10px 25px rgba(15,23,42,0.08);text-align:center}
.psk-btn{background:#00c3f7;color:#fff;border:none;padding:15px 30px;border-radius:5px;font-size:16px;cursor:pointer;width:100%;font-weight:bold;text-decoration:none;display:inline-block}
.psk-btn:hover{background:#00b0e0}
.psk-btn[disabled]{opacity:.6;cursor:default}
.info{background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:15px;text-align:left}
.info p{margin:5px 0;font-size:14px}
</style>

<div class="paystack-container">
    <div class="info">
        <p><strong>Invoice:</strong> <?= htmlspecialchars($booking['invoice_id']) ?></p>
        <p><strong>Amount:</strong> <?= htmlspecialchars($currency . ' ' . $amount) ?></p>
        <p><strong>Email:</strong> <?= htmlspecialchars($email) ?></p>
    </div>

    <button type="button" id="pskPayBtn" class="psk-btn">
        Pay <?= htmlspecialchars($currency . ' ' . $amount) ?>
    </button>
    <p id="pskMsg" style="margin-top:12px;font-size:13px;color:#ef4444;display:none;"></p>
    <p style="margin-top:15px;font-size:12px;color:#94a3b8">Secured by Paystack</p>
</div>

<script>
(function () {
    var ACCESS_CODE = <?= json_encode($accessCode) ?>;
    var PUBLIC_KEY  = <?= json_encode($publicKey) ?>;
    var CALLBACK    = <?= json_encode($callbackUrl) ?>;   // our Verify callback (payment_status=success&token=…)
    var AUTH_URL    = <?= json_encode($authUrl) ?>;       // hosted-page fallback
    var btn = document.getElementById('pskPayBtn');
    var msg = document.getElementById('pskMsg');
    function showMsg(t){ if(msg){ msg.textContent = t; msg.style.display = 'block'; } }
    function reset(){ if(btn){ btn.disabled = false; btn.textContent = 'Pay <?= htmlspecialchars($currency . ' ' . $amount, ENT_QUOTES) ?>'; } }

    function launch() {
        if (btn) { btn.disabled = true; btn.textContent = 'Opening secure checkout…'; }
        console.log('[PAYLOG] paystack popup: launch resumeTransaction', { hasCode: !!ACCESS_CODE });
        try {
            var pop = new Paystack();
            pop.resumeTransaction(ACCESS_CODE, {
                onSuccess: function (txn) {
                    console.log('[PAYLOG] paystack popup: onSuccess', txn && txn.reference);
                    // Hand off to our server callback, which RE-VERIFIES with Paystack.
                    window.top.location.href = CALLBACK;
                },
                onCancel: function () {
                    console.log('[PAYLOG] paystack popup: onCancel');
                    showMsg('Payment cancelled. You can try again.');
                    reset();
                },
                onError: function (err) {
                    console.error('[PAYLOG] paystack popup: onError', err);
                    showMsg('Payment error. Please try again.');
                    reset();
                }
            });
        } catch (e) {
            // InlineJS failed to load/run → fall back to the hosted page at top level.
            console.error('[PAYLOG] paystack popup: exception, falling back to hosted page', e);
            if (AUTH_URL) { window.top.location.href = AUTH_URL; }
            else { showMsg('Could not open the payment window. Please try again.'); reset(); }
        }
    }

    // Load InlineJS v2 then wire the button. If the library is already present, reuse it.
    function ready() {
        if (btn) { btn.addEventListener('click', launch); }
        // auto-open once the library is ready (the customer already clicked "Proceed")
        launch();
    }
    if (window.Paystack) {
        ready();
    } else {
        var s = document.createElement('script');
        s.src = 'https://js.paystack.co/v2/inline.js';
        s.onload = ready;
        s.onerror = function () {
            // CDN blocked/offline → top-level redirect to the hosted checkout (still works).
            console.error('[PAYLOG] paystack popup: inline.js failed to load, falling back');
            if (AUTH_URL) { window.top.location.href = AUTH_URL; }
            else { showMsg('Could not load the payment library. Please try again.'); }
        };
        document.head.appendChild(s);
    }
})();
</script>

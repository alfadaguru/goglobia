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

$amountInKobo = (float)$booking['price_markup'] * 100;
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

$email = $booking['email'] ?? 'customer@example.com';
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
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

$result = json_decode($response, true);

if ($curlError) {
    echo '<p style="color:red">Connection error: ' . htmlspecialchars($curlError) . '</p>';
    return;
}

if ($result && $result['status'] && isset($result['data']['authorization_url'])) {
    $authUrl = $result['data']['authorization_url'];

    // Auto-redirect in production, show button in dev mode
    if (empty($config['dev_mode'])) {
        header("Location: {$authUrl}");
        exit;
    }
} else {
    $errorMsg = $result['message'] ?? 'Unknown error';
    echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px">';
    echo '<p style="color:red"><strong>Payment initialization failed:</strong><br>' . htmlspecialchars($errorMsg) . '</p>';
    echo '<p style="font-size:12px;color:#666">HTTP Code: ' . $httpCode . '</p>';
    if (!empty($config['dev_mode'])) {
        echo '<pre style="background:#f5f5f5;padding:10px;font-size:11px;overflow:auto">' . htmlspecialchars($response) . '</pre>';
    }
    echo '</div>';
    return;
}
} catch (\Throwable $e) {
    error_log("PAYSTACK_PAYMENT ERROR: " . $e->getMessage());
    echo '<p style="color:red">Payment processing error. Please try again.</p>';
    return;
}
?>

<style>
.paystack-container{max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px;box-shadow:0 10px 25px rgba(15,23,42,0.08);text-align:center}
.psk-btn{background:#00c3f7;color:#fff;border:none;padding:15px 30px;border-radius:5px;font-size:16px;cursor:pointer;width:100%;font-weight:bold;text-decoration:none;display:inline-block}
.psk-btn:hover{background:#00b0e0}
.info{background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:15px;text-align:left}
.info p{margin:5px 0;font-size:14px}
</style>

<div class="paystack-container">
    <div class="info">
        <p><strong>Invoice:</strong> <?= htmlspecialchars($booking['invoice_id']) ?></p>
        <p><strong>Amount:</strong> <?= htmlspecialchars($currency . ' ' . $amount) ?></p>
        <p><strong>Email:</strong> <?= htmlspecialchars($email) ?></p>
    </div>

    <a href="<?= htmlspecialchars($authUrl) ?>" class="psk-btn">
        Pay <?= htmlspecialchars($currency . ' ' . $amount) ?>
    </a>
    <p style="margin-top:15px;font-size:12px;color:#94a3b8">Secured by Paystack</p>
</div>

<script>
// Auto-redirect after showing info
(function() {
    const isDevMode = <?= json_encode(!empty($config['dev_mode'])) ?>;
    const delay = isDevMode ? 1500 : 500; // Longer delay in dev mode to read credentials
    
    setTimeout(function() {
        window.location.href = '<?= htmlspecialchars($authUrl) ?>';
    }, delay);
})();
</script>

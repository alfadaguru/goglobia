<?php

// ============================================================================
// CASHFREE PAYMENT GATEWAY - REDIRECT CHECKOUT
// ============================================================================
// HANDLES SECURE PAYMENT PROCESSING VIA CASHFREE PG
// CREATES AN ORDER VIA CASHFREE API AND REDIRECTS USER TO HOSTED CHECKOUT
// API DOCS: https://www.cashfree.com/docs/api-reference/payments/latest/overview
// ============================================================================

/*
Cashfree Test Credentials:
Test Mode (Sandbox)
Card: 4111111111111111
CVV: 123 | Expiry: 12/25
OTP: 123456
UPI: testsuccess@gocash
*/

if (!isset($_POST['payload']) && !isset($paymentData)) return;

// ============================================================================
// EXTRACT PAYMENT DATA FROM REQUEST
// ============================================================================
// SUPPORTS TWO FORMATS:
// 1. LEGACY FORMAT: BASE64 ENCODED PAYLOAD IN $_POST['payload']
// 2. NEW FORMAT: STRUCTURED $paymentData ARRAY FROM PAYMENT LIBRARY
// ============================================================================

if (isset($_POST['payload'])) {
    // LEGACY FORMAT COMPATIBILITY - DECODE BASE64 PAYLOAD
    $payload = json_decode(base64_decode($_POST['payload']));
    if (!$payload) { echo '<p style="color:red">Invalid payment payload.</p>'; return; }

    $booking = [
        'invoice_id' => $payload->booking_ref_no ?? $payload->invoice_id,
        'email' => $payload->client_email,
        'price_markup' => $payload->price,
        'currency_markup' => $payload->currency,
        'phone' => $payload->client_phone ?? '9999999999',
        'first_name' => $payload->first_name ?? 'Customer',
    ];

    $token = $_POST['payment_token'] ?? $_POST['payload'];
    $tokenParam = urlencode($token ?? '');
    $successUrl = $_POST['success_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . $tokenParam . '&payment_status=success');
    $cancelUrl = $_POST['cancel_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . $tokenParam . '&payment_status=cancel');

    // RESOLVE GATEWAY CONFIGURATION
    if (isset($gateway) && is_array($gateway)) {
        $config = get_gateway_config($gateway);
    } else {
        global $db;
        $gateway = $db->get('payment_gateways', '*', ['name' => 'Cashfree', 'status' => 1]);
        $config = get_gateway_config($gateway ?: []);
    }
} else {
    // NEW PAYMENT LIBRARY FORMAT
    $booking = $paymentData['booking'];
    $gateway = $paymentData['gateway'];
    $successUrl = $paymentData['success_url'];
    $cancelUrl = $paymentData['cancel_url'];
    $config = get_gateway_config($gateway);
}

// ============================================================================
// EXTRACT CASHFREE CREDENTIALS
// ============================================================================
// c1 = App ID (x-client-id)
// c2 = Secret Key (x-client-secret)
// ============================================================================
$appId = $config['api_key'] ?? $gateway['c1'] ?? '';
$secretKey = $config['secret_key'] ?? $gateway['c2'] ?? '';

if (empty($appId) || empty($secretKey)) {
    echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px">';
    echo '<p style="color:red"><strong>Cashfree Configuration Error</strong></p>';
    echo '<p style="color:#666">App ID or Secret Key not configured.</p>';
    if (!empty($config['dev_mode']) || !empty($gateway['dev_mode'])) {
        echo '<p style="font-size:12px;color:#999">Debug - Config keys: ' . implode(', ', array_keys($config)) . '</p>';
        echo '<p style="font-size:12px;color:#999">Debug - Gateway keys: ' . implode(', ', array_keys($gateway ?? [])) . '</p>';
    }
    echo '</div>';
    return;
}

// ============================================================================
// DETERMINE ENVIRONMENT (SANDBOX vs PRODUCTION)
// ============================================================================
$isDevMode = !empty($config['dev_mode']) || !empty($gateway['dev_mode']);
$baseUrl = $isDevMode
    ? 'https://sandbox.cashfree.com/pg'
    : 'https://api.cashfree.com/pg';
$cfMode = $isDevMode ? 'sandbox' : 'production';

// ============================================================================
// PREPARE ORDER DATA
// ============================================================================
$orderAmount = round((float)$booking['price_markup'], 2);
$currency = strtoupper($booking['currency_markup'] ?? 'INR');
$email = $booking['email'] ?? 'customer@example.com';
$phone = $booking['phone'] ?? '9999999999';
$customerName = $booking['first_name'] ?? 'Customer';
$orderId = 'CF-' . $booking['invoice_id'] . '-' . time();

// Cashfree supported currencies: INR, USD, EUR, GBP and more
// Override currency if specified in gateway config
if (!empty($config['currency'])) {
    $currency = strtoupper($config['currency']);
}

// Validate amount
if ($orderAmount < 1) {
    echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px">';
    echo '<p style="color:red">Invalid payment amount. Minimum is 1.00</p>';
    echo '</div>';
    return;
}

// Build return URL - includes payment_status=success to trigger invoice route handler.
// Cashfree only supports a single return URL for all outcomes.
// Server-side verification in handle_payment_callback() will override this
// if the payment was actually cancelled/failed.
$tokenParam = urlencode($paymentData['token'] ?? $token ?? '');
$invoiceBase = root . 'invoice/' . $booking['invoice_id'];
$returnUrl = $invoiceBase . '?token=' . $tokenParam . '&payment_status=success&gateway=cashfree&transaction_id=' . $orderId;

// ============================================================================
// CREATE CASHFREE ORDER VIA API
// ============================================================================
try {
    $orderData = [
        'order_id' => $orderId,
        'order_amount' => $orderAmount,
        'order_currency' => $currency,
        'customer_details' => [
            'customer_id' => 'CUST-' . preg_replace('/[^a-zA-Z0-9]/', '', $booking['invoice_id']),
            'customer_email' => $email,
            'customer_phone' => preg_replace('/[^0-9]/', '', $phone) ?: '9999999999',
            'customer_name' => $customerName,
        ],
        'order_meta' => [
            'return_url' => $returnUrl,
        ],
        'order_note' => 'Payment for Invoice ' . $booking['invoice_id'],
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl . '/orders');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($orderData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-version: 2023-08-01',
        'x-client-id: ' . $appId,
        'x-client-secret: ' . $secretKey,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Check for cURL errors
    if ($curlError) {
        echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px">';
        echo '<p style="color:red">Connection error: ' . htmlspecialchars($curlError) . '</p>';
        echo '</div>';
        return;
    }

    $result = json_decode($response, true);

    // ============================================================================
    // VALIDATE API RESPONSE
    // ============================================================================
    if ($httpCode !== 200 || empty($result['payment_session_id'])) {
        $errorMsg = $result['message'] ?? ($result['type'] ?? 'Unknown error');
        echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px">';
        echo '<p style="color:red"><strong>Payment initialization failed:</strong><br>' . htmlspecialchars($errorMsg) . '</p>';
        echo '<p style="font-size:12px;color:#666">HTTP Code: ' . $httpCode . '</p>';
        if ($isDevMode) {
            echo '<pre style="background:#f5f5f5;padding:10px;font-size:11px;overflow:auto;max-height:200px">' . htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT)) . '</pre>';
            echo '<details style="margin-top:10px"><summary style="cursor:pointer;font-size:12px;color:#999">Request Debug</summary>';
            echo '<pre style="background:#f5f5f5;padding:10px;font-size:11px;overflow:auto">' . htmlspecialchars(json_encode($orderData, JSON_PRETTY_PRINT)) . '</pre>';
            echo '</details>';
        }
        echo '</div>';
        return;
    }

    // ============================================================================
    // ORDER CREATED SUCCESSFULLY - PREPARE CHECKOUT
    // ============================================================================
    $paymentSessionId = $result['payment_session_id'];
    $cfOrderId = $result['cf_order_id'] ?? '';
    $amount = number_format($orderAmount, 2, '.', '');

} catch (\Throwable $e) {
    error_log("CASHFREE_PAYMENT ERROR: " . $e->getMessage());
    echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px">';
    echo '<p style="color:red">Payment processing error. Please try again.</p>';
    if ($isDevMode) {
        echo '<p style="font-size:12px;color:#999">' . htmlspecialchars($e->getMessage()) . '</p>';
    }
    echo '</div>';
    return;
}
?>

<!-- ============================================================================ -->
<!-- CASHFREE CHECKOUT - JS SDK REDIRECT -->
<!-- ============================================================================ -->
<style>
.cashfree-container{max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px;box-shadow:0 10px 25px rgba(15,23,42,0.08);text-align:center}
.cf-btn{background:#5469d4;color:#fff;border:none;padding:15px 30px;border-radius:8px;font-size:16px;cursor:pointer;width:100%;font-weight:600;text-decoration:none;display:inline-block;transition:background 0.2s}
.cf-btn:hover{background:#4558b8}
.cf-btn:disabled{opacity:0.7;cursor:not-allowed}
.cf-info{background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:15px;text-align:left}
.cf-info p{margin:5px 0;font-size:14px;color:#334155}
.cf-info strong{color:#0f172a}
.cf-spinner{display:inline-block;width:18px;height:18px;border:2px solid rgba(255,255,255,0.3);border-radius:50%;border-top-color:#fff;animation:cf-spin 0.6s linear infinite;vertical-align:middle;margin-left:8px}
@keyframes cf-spin{to{transform:rotate(360deg)}}
</style>

<div class="cashfree-container">
    <div class="cf-info">
        <p><strong>Invoice:</strong> <?= htmlspecialchars($booking['invoice_id']) ?></p>
        <p><strong>Amount:</strong> <?= htmlspecialchars($currency . ' ' . $amount) ?></p>
        <p><strong>Email:</strong> <?= htmlspecialchars($email) ?></p>
    </div>

    <button type="button" id="cf-pay-btn" class="cf-btn" disabled>
        <span id="cf-btn-text">Loading...</span>
        <span id="cf-btn-spinner" class="cf-spinner" style="display:none"></span>
    </button>

    <p style="margin-top:15px;font-size:12px;color:#94a3b8">Secured by Cashfree</p>
</div>

<script>
(function() {
    const paymentSessionId = <?= json_encode($paymentSessionId) ?>;
    const cfMode = <?= json_encode($cfMode) ?>;
    const isDevMode = <?= json_encode($isDevMode) ?>;
    const payAmount = <?= json_encode($currency . ' ' . $amount) ?>;

    // Load Cashfree SDK dynamically and wait for it
    function loadCashfreeSDK(callback) {
        // Check if already loaded
        if (typeof Cashfree !== 'undefined') {
            callback();
            return;
        }

        const script = document.createElement('script');
        script.src = 'https://sdk.cashfree.com/js/v3/cashfree.js';
        script.onload = callback;
        script.onerror = function() {
            const btn = document.getElementById('cf-pay-btn');
            const btnText = document.getElementById('cf-btn-text');
            if (btn) { btnText.textContent = 'SDK Load Failed - Retry'; btn.disabled = false; btn.onclick = function() { loadCashfreeSDK(initCheckout); }; }
        };
        document.head.appendChild(script);
    }

    function initCheckout() {
        const cashfree = Cashfree({ mode: cfMode });
        const btn = document.getElementById('cf-pay-btn');
        const btnText = document.getElementById('cf-btn-text');
        const btnSpinner = document.getElementById('cf-btn-spinner');

        // Enable button
        if (btn) {
            btnText.textContent = 'Pay ' + payAmount;
            btn.disabled = false;
            btn.onclick = function() { doCheckout(cashfree); };
        }

        // Auto-trigger after short delay
        const delay = isDevMode ? 1500 : 800;
        setTimeout(function() { doCheckout(cashfree); }, delay);
    }

    function doCheckout(cashfree) {
        const btn = document.getElementById('cf-pay-btn');
        const btnText = document.getElementById('cf-btn-text');
        const btnSpinner = document.getElementById('cf-btn-spinner');

        if (btn) {
            btn.disabled = true;
            btnText.textContent = 'Redirecting...';
            btnSpinner.style.display = 'inline-block';
        }

        cashfree.checkout({
            paymentSessionId: paymentSessionId,
            redirectTarget: '_self',
        });
    }

    // Start loading SDK
    loadCashfreeSDK(initCheckout);
})();
</script>

<?php

// ============================================================================
// XMONEY (UTRUST) PAYMENT GATEWAY
// ============================================================================
// HANDLES SECURE CRYPTO PAYMENTS VIA XMONEY REST API
// REDIRECTS USERS TO THE XMONEY CRYPTO CHECKOUT DASHBOARD
// ============================================================================

// CHECK IF PAYMENT DATA EXISTS
if (!isset($_POST['payload']) && !isset($paymentData)) {
    return;
}

// ============================================================================
// EXTRACT PAYMENT DATA
// =========================================================

if (isset($_POST['payload'])) {
    $payload = json_decode(base64_decode($_POST['payload']));
    $booking = [
        'invoice_id' => $payload->booking_ref_no ?? $payload->invoice_id,
        'email' => $payload->client_email,
        'price_markup' => $payload->price,
        'currency_markup' => $payload->currency,
    ];
    $token = $_POST['payment_token'] ?? $_POST['payload'];
    $successUrl = $_POST['success_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . urlencode($token) . '&payment_status=success&gateway=xmoney');
    $cancelUrl = $_POST['cancel_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . urlencode($token) . '&payment_status=cancel&gateway=xmoney');
    
    global $db;
    $gateway = $db->get('payment_gateways', '*', ['name' => 'xMoney', 'status' => 1]);
    $config = get_gateway_config($gateway ?: []);
} elseif (isset($paymentData)) {
    $booking = $paymentData['booking'];
    $gateway = $paymentData['gateway'];
    $token = $paymentData['token'];
    $successUrl = $paymentData['success_url'] . '&gateway=xmoney';
    $cancelUrl = $paymentData['cancel_url'] . '&gateway=xmoney';
    $config = get_gateway_config($gateway);
} else {
    return;
}

// ============================================================================
// XMONEY API CONFIGURATION
// =========================================================
$apiKey = $config['api_key'] ?? '';
$isSandbox = ($config['dev_mode'] == 1);
$baseUrl = $isSandbox 
    ? 'https://merchants.api.sandbox.crypto.xmoney.com/api' 
    : 'https://merchants.api.crypto.xmoney.com/api';

if (empty($apiKey)) {
    echo '<p style="color:red;text-align:center;">xMoney is not configured. Please add your API Key in settings.</p>';
    return;
}

// ============================================================================
// PREPARE XMONEY PAYLOAD (JSON:API FORMAT)
// =========================================================
$data = [
    'data' => [
        'type' => 'orders',
        'attributes' => [
            'order' => [
                'reference' => $booking['invoice_id'],
                'amount' => [
                    'total' => number_format((float)$booking['price_markup'], 2, '.', ''),
                    'currency' => strtoupper($booking['currency_markup'] ?? 'USD')
                ],
                'return_urls' => [
                    'return_url' => $successUrl,
                    'cancel_url' => $cancelUrl
                ]
            ],
            'customer' => [
                'email' => $booking['email'],
                'country' => 'US' // Default country required by xMoney
            ]
        ]
    ]
];

// ============================================================================
// EXECUTE API REQUEST
// =========================================================
$ch = curl_init($baseUrl . '/stores/orders');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/vnd.api+json',
        'Accept: application/vnd.api+json'
    ],
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($response, true);

if (($httpCode === 200 || $httpCode === 201) && isset($result['data']['attributes']['redirect_url'])) {
    $redirectUrl = $result['data']['attributes']['redirect_url'];
    ?>
    <div style="text-align:center;padding:40px;color:#64748b;font-family:sans-serif;">
        <h3 style="color:#0f172a;margin-bottom:10px;">Redirecting to xMoney Checkout</h3>
        <p style="margin-bottom:20px;">Please wait while we connect you to our secure crypto payment provider...</p>
        <div style="margin:0 auto;width:40px;height:40px;border:4px solid #e2e8f0;border-top-color:#5f81ff;border-radius:50%;animation:spin 0.8s linear infinite;"></div>
    </div>
    <style>@keyframes spin { to { transform: rotate(360deg); } }</style>
    <script>window.location.href = <?= json_encode($redirectUrl) ?>;</script>
    <?php
} else {
    echo '<div style="max-width:500px;margin:20px auto;padding:20px;background:#fef2f2;border:1px solid #fee2e2;border-radius:8px;">';
    echo '<h4 style="color:#991b1b;margin-top:0;">xMoney API Error</h4>';
    if (isset($result['errors'])) {
        foreach ($result['errors'] as $error) {
            $msg = is_array($error['detail']) ? json_encode($error['detail']) : $error['detail'];
            echo '<p style="color:#b91c1c;font-size:14px;">• ' . htmlspecialchars($msg) . '</p>';
        }
    } else {
        echo '<p>HTTP Error ' . $httpCode . ': ' . htmlspecialchars(substr($response, 0, 200)) . '</p>';
    }
    echo '<p style="margin-top:15px;"><a href="' . htmlspecialchars(root . 'invoice/' . $booking['invoice_id']) . '" style="color:#2563eb;">← Back to Invoice</a></p>';
    echo '</div>';
}

<?php

// ============================================================================
// M-PESA PAYMENT GATEWAY - CUSTOMER TO BUSINESS (C2B) INTEGRATION
// ============================================================================
// HANDLES SECURE PAYMENT PROCESSING VIA M-PESA OPENAPI
// SUPPORTS BOTH LEGACY PAYLOAD FORMAT AND NEW PAYMENT LIBRARY FORMAT
// IMPLEMENTS TWO-STEP AUTHENTICATION: SESSION KEY + PAYMENT REQUEST
// SUPPORTS C2B SINGLE PAYMENT FOR BOOKING TRANSACTIONS
// ============================================================================

// CHECK IF PAYMENT DATA EXISTS - EXIT IF NO PAYMENT REQUEST DETECTED
if (!isset($_POST['payload']) && !isset($paymentData)) {
    return;
}

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
    if (!$payload) {
        echo '<p style="color:red">Invalid payment payload.</p>';
        return;
    }

    $booking = [
        'invoice_id' => $payload->booking_ref_no ?? $payload->invoice_id,
        'email' => $payload->client_email,
        'phone' => $payload->client_phone ?? '',
        'price_markup' => $payload->price,
        'currency_markup' => $payload->currency,
        'ref' => $payload->booking_ref_no ?? $payload->invoice_id
    ];

    $token = $paymentData['token'] ?? ($_POST['payment_token'] ?? $_POST['payload']);
    $tokenParam = urlencode($token ?? '');
    $successUrl = $_POST['success_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . $tokenParam . '&payment_status=success');
    $cancelUrl = $_POST['cancel_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . $tokenParam . '&payment_status=cancel');

    // RESOLVE GATEWAY CONFIGURATION FROM MULTIPLE SOURCES
    if (isset($paymentData) && isset($paymentData['gateway']) && is_array($paymentData['gateway'])) {
        $gateway = $paymentData['gateway'];
        $config = get_gateway_config($gateway);
    } elseif (isset($gateway) && is_array($gateway)) {
        // $gateway can be injected by the route including this file
        $config = get_gateway_config($gateway);
    } else {
        global $db;
        $gateway = $db->get('payment_gateways', '*', ['name' => 'M-Pesa', 'status' => 1]);
        $config = get_gateway_config($gateway ?: []);
    }
} elseif (isset($paymentData)) {
    // NEW PAYMENT LIBRARY FORMAT - STRUCTURED DATA ARRAY
    $booking = $paymentData['booking'];
    $gateway = $paymentData['gateway'];
    $token = $paymentData['token'];
    $successUrl = $paymentData['success_url'];
    $cancelUrl = $paymentData['cancel_url'];

    // EXTRACT GATEWAY CONFIGURATION USING HELPER FUNCTION
    $config = get_gateway_config($gateway);
} else {
    return;
}

// ============================================================================
// VALIDATE M-PESA CONFIGURATION - REQUIRED FOR API AUTHENTICATION
// ============================================================================
$apiKey = $config['api_key'] ?? $config['public_key'] ?? '';
$serviceProviderCode = $config['service_provider_code'] ?? $config['merchant_code'] ?? '';
$apiUrl = $config['api_url'] ?? 'https://openapi.m-pesa.com'; // Default to production
$market = $config['market'] ?? 'TZN'; // Default market (Tanzania)

// Use sandbox URL if in dev mode
if (!empty($config['dev_mode'])) {
    $apiUrl = $config['sandbox_url'] ?? 'https://sandbox.m-pesa.com';
}

if (empty($apiKey) || empty($serviceProviderCode)) {
    echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px;border:1px solid #fecaca;">';
    echo '<h4 style="color:#dc2626;margin-top:0;">M-Pesa Configuration Error</h4>';
    echo '<p style="color:#991b1b;">M-Pesa API credentials are not configured. Please contact support.</p>';
    
    if (!empty($config['dev_mode'])) {
        echo '<details style="margin-top:15px;font-size:12px;color:#6b7280;">';
        echo '<summary style="cursor:pointer;">Debug Information</summary>';
        echo '<pre style="background:#f3f4f6;padding:10px;margin-top:10px;overflow:auto;">';
        echo 'API Key Present: ' . (empty($apiKey) ? 'NO' : 'YES') . "\n";
        echo 'Service Provider Code: ' . (empty($serviceProviderCode) ? 'NOT SET' : 'SET') . "\n";
        echo '</pre>';
        echo '</details>';
    }
    
    echo '</div>';
    return;
}

// ============================================================================
// FORMAT AMOUNT AND CURRENCY FOR M-PESA API
// ============================================================================
$amount = number_format((float) $booking['price_markup'], 2, '.', '');
$currency = strtoupper($booking['currency_markup'] ?? 'TZS');

// ============================================================================
// GENERATE UNIQUE TRANSACTION REFERENCE
// ============================================================================
$txRef = 'MPESA-' . $booking['invoice_id'] . '-' . time();

// ============================================================================
// EXTRACT CUSTOMER PHONE NUMBER - REQUIRED FOR M-PESA
// ============================================================================
$customerPhone = $booking['phone'] ?? '';

// Format phone number (remove spaces, dashes, plus sign)
$customerPhone = preg_replace('/[^0-9]/', '', $customerPhone);

// Ensure phone number starts with country code
if (!empty($config['country_code']) && !str_starts_with($customerPhone, $config['country_code'])) {
    // Add country code if not present (e.g., 255 for Tanzania)
    if (strlen($customerPhone) >= 9 && str_starts_with($customerPhone, '0')) {
        $customerPhone = $config['country_code'] . substr($customerPhone, 1);
    } elseif (strlen($customerPhone) >= 9) {
        $customerPhone = $config['country_code'] . $customerPhone;
    }
}

// ============================================================================
// STEP 1: GENERATE SESSION KEY - AUTHENTICATION
// ============================================================================
function generateMpesaSessionKey($apiUrl, $apiKey, $market) {
    $sessionUrl = rtrim($apiUrl, '/') . '/sandbox/ipg/v2/vodacomTZN/getSession/';
    
    // Encode API Key to Base64 for authorization
    $encodedKey = base64_encode($apiKey);
    
    $headers = [
        'Authorization: Bearer ' . $encodedKey,
        'Content-Type: application/json',
        'Origin: *'
    ];
    
    $ch = curl_init($sessionUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable in dev, enable in production
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    
    if ($curlError) {
        return ['success' => false, 'error' => 'Connection error: ' . $curlError];
    }
    
    $result = json_decode($response, true);
    
    if ($httpCode === 200 && isset($result['output_SessionID'])) {
        return [
            'success' => true,
            'session_id' => $result['output_SessionID']
        ];
    }
    
    return [
        'success' => false,
        'error' => $result['output_ResponseDesc'] ?? 'Failed to generate session key',
        'code' => $result['output_ResponseCode'] ?? 'UNKNOWN',
        'http_code' => $httpCode,
        'response' => $response
    ];
}

// ============================================================================
// STEP 2: INITIATE C2B PAYMENT REQUEST
// ============================================================================
function initiateMpesaPayment($apiUrl, $sessionId, $serviceProviderCode, $amount, $customerPhone, $txRef, $market) {
    $paymentUrl = rtrim($apiUrl, '/') . '/sandbox/ipg/v2/vodacomTZN/c2bPayment/singleStage/';
    
    $payload = [
        'input_Amount' => $amount,
        'input_Country' => $market,
        'input_Currency' => 'TZS', // M-Pesa Tanzania uses TZS
        'input_CustomerMSISDN' => $customerPhone,
        'input_ServiceProviderCode' => $serviceProviderCode,
        'input_ThirdPartyConversationID' => $txRef,
        'input_TransactionReference' => $txRef,
        'input_PurchasedItemsDesc' => 'Travel Booking Payment'
    ];
    
    $headers = [
        'Authorization: Bearer ' . $sessionId,
        'Content-Type: application/json',
        'Origin: *'
    ];
    
    $ch = curl_init($paymentUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    
    if ($curlError) {
        return ['success' => false, 'error' => 'Connection error: ' . $curlError];
    }
    
    $result = json_decode($response, true);
    
    if ($httpCode === 200 && isset($result['output_ResponseCode']) && $result['output_ResponseCode'] === 'INS-0') {
        return [
            'success' => true,
            'transaction_id' => $result['output_TransactionID'] ?? $txRef,
            'conversation_id' => $result['output_ConversationID'] ?? '',
            'message' => $result['output_ResponseDesc'] ?? 'Payment initiated successfully'
        ];
    }
    
    return [
        'success' => false,
        'error' => $result['output_ResponseDesc'] ?? 'Payment initiation failed',
        'code' => $result['output_ResponseCode'] ?? 'UNKNOWN',
        'http_code' => $httpCode,
        'response' => $response
    ];
}

// ============================================================================
// EXECUTE PAYMENT FLOW
// ============================================================================
try {
$sessionResult = generateMpesaSessionKey($apiUrl, $apiKey, $market);

if (!$sessionResult['success']) {
    // SESSION GENERATION FAILED
    echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px;border:1px solid #fecaca;">';
    echo '<h4 style="color:#dc2626;margin-top:0;">M-Pesa Authentication Error</h4>';
    echo '<p style="color:#991b1b;">' . htmlspecialchars($sessionResult['error']) . '</p>';
    
    if (!empty($config['dev_mode'])) {
        echo '<details style="margin-top:15px;font-size:12px;color:#6b7280;">';
        echo '<summary style="cursor:pointer;">Debug Information</summary>';
        echo '<pre style="background:#f3f4f6;padding:10px;margin-top:10px;overflow:auto;">';
        echo 'HTTP Code: ' . ($sessionResult['http_code'] ?? 'N/A') . "\n";
        echo 'Error Code: ' . ($sessionResult['code'] ?? 'N/A') . "\n";
        echo 'Response: ' . ($sessionResult['response'] ?? 'N/A') . "\n";
        echo '</pre>';
        echo '</details>';
    }
    
    echo '<p style="margin-top:15px;"><a href="' . htmlspecialchars($cancelUrl) . '" style="color:#2563eb;">← Return to Invoice</a></p>';
    echo '</div>';
    return;
}

// SESSION KEY GENERATED SUCCESSFULLY - INITIATE PAYMENT
$sessionId = $sessionResult['session_id'];
$paymentResult = initiateMpesaPayment($apiUrl, $sessionId, $serviceProviderCode, $amount, $customerPhone, $txRef, $market);

if (!$paymentResult['success']) {
    // PAYMENT INITIATION FAILED
    echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px;border:1px solid #fecaca;">';
    echo '<h4 style="color:#dc2626;margin-top:0;">M-Pesa Payment Error</h4>';
    echo '<p style="color:#991b1b;">' . htmlspecialchars($paymentResult['error']) . '</p>';
    
    if (!empty($config['dev_mode'])) {
        echo '<details style="margin-top:15px;font-size:12px;color:#6b7280;">';
        echo '<summary style="cursor:pointer;">Debug Information</summary>';
        echo '<pre style="background:#f3f4f6;padding:10px;margin-top:10px;overflow:auto;">';
        echo 'HTTP Code: ' . ($paymentResult['http_code'] ?? 'N/A') . "\n";
        echo 'Error Code: ' . ($paymentResult['code'] ?? 'N/A') . "\n";
        echo 'Phone: ' . $customerPhone . "\n";
        echo 'Amount: ' . $amount . ' ' . $currency . "\n";
        echo 'Response: ' . ($paymentResult['response'] ?? 'N/A') . "\n";
        echo '</pre>';
        echo '</details>';
    }
    
    echo '<p style="margin-top:15px;"><a href="' . htmlspecialchars($cancelUrl) . '" style="color:#2563eb;">← Return to Invoice</a></p>';
    echo '</div>';
    return;
}

// ============================================================================
// PAYMENT INITIATED SUCCESSFULLY - SHOW SUCCESS MESSAGE AND REDIRECT
// ============================================================================
$transactionId = $paymentResult['transaction_id'];
$conversationId = $paymentResult['conversation_id'];

// Build success URL with transaction details
$separator = (strpos($successUrl, '?') === false) ? '?' : '&';
$redirectUrl = $successUrl . $separator . 'transaction_id=' . urlencode($transactionId) . '&gateway=mpesa&conversation_id=' . urlencode($conversationId);

} catch (\Throwable $e) {
    error_log("MPESA_PAYMENT ERROR: " . $e->getMessage());
    echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px;border:1px solid #fecaca;">';
    echo '<h4 style="color:#dc2626;margin-top:0;">Payment Error</h4>';
    echo '<p style="color:#991b1b;">An unexpected error occurred. Please try again.</p>';
    echo '<p style="margin-top:15px;"><a href="' . htmlspecialchars($cancelUrl) . '" style="color:#2563eb;">← Return to Invoice</a></p>';
    echo '</div>';
    return;
}

?>

<style>
    .mpesa-container {
        max-width: 420px;
        margin: 0 auto;
        padding: 20px;
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
        text-align: center;
    }

    .mpesa-success {
        background: #d1fae5;
        border: 1px solid #6ee7b7;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
    }

    .mpesa-success h3 {
        color: #065f46;
        margin: 0 0 10px 0;
    }

    .mpesa-success p {
        color: #047857;
        margin: 5px 0;
        font-size: 14px;
    }

    .mpesa-instructions {
        background: #fef3c7;
        border: 1px solid #fbbf24;
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 20px;
        text-align: left;
    }

    .mpesa-instructions h4 {
        color: #92400e;
        margin: 0 0 10px 0;
        font-size: 16px;
    }

    .mpesa-instructions ol {
        color: #78350f;
        margin: 0;
        padding-left: 20px;
        font-size: 14px;
    }

    .mpesa-instructions li {
        margin: 5px 0;
    }

    .payment-info {
        background: #f8fafc;
        border-radius: 8px;
        padding: 12px;
        margin-bottom: 15px;
        text-align: left;
    }

    .payment-info p {
        margin: 5px 0;
        font-size: 14px;
    }

    .payment-info strong {
        color: #1e293b;
    }

    .mpesa-logo {
        font-size: 24px;
        font-weight: bold;
        color: #22c55e;
        margin-bottom: 15px;
    }

    .spinner {
        margin: 20px auto;
        width: 40px;
        height: 40px;
        border: 4px solid #e5e7eb;
        border-top-color: #22c55e;
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    .redirect-btn {
        background: #22c55e;
        color: white;
        border: none;
        padding: 12px 30px;
        border-radius: 8px;
        font-size: 14px;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
        margin-top: 10px;
    }

    .redirect-btn:hover {
        background: #16a34a;
    }
</style>

<div class="mpesa-container">
    <div class="mpesa-logo">M-PESA</div>
    
    <div class="mpesa-success">
        <h3>✓ Payment Request Sent!</h3>
        <p><?= htmlspecialchars($paymentResult['message']) ?></p>
    </div>

    <div class="mpesa-instructions">
        <h4>📱 Complete Payment on Your Phone</h4>
        <ol>
            <li>Check your phone for M-Pesa payment request</li>
            <li>Enter your M-Pesa PIN to authorize payment</li>
            <li>Wait for confirmation SMS from M-Pesa</li>
        </ol>
    </div>

    <div class="payment-info">
        <p><strong>Invoice:</strong> <?= htmlspecialchars($booking['invoice_id']) ?></p>
        <p><strong>Amount:</strong> <?= htmlspecialchars($currency) ?> <?= htmlspecialchars($amount) ?></p>
        <p><strong>Phone:</strong> <?= htmlspecialchars($customerPhone) ?></p>
        <p><strong>Transaction ID:</strong> <?= htmlspecialchars($transactionId) ?></p>
    </div>

    <p style="color:#64748b;font-size:14px;margin:15px 0;">
        Redirecting to invoice page...
    </p>
    
    <div class="spinner"></div>

    <a href="<?= htmlspecialchars($redirectUrl) ?>" class="redirect-btn">
        Continue to Invoice
    </a>
</div>

<script>
(function() {
    const redirectUrl = <?= json_encode($redirectUrl) ?>;
    const isDevMode = <?= json_encode(!empty($config['dev_mode'])) ?>;
    const delay = isDevMode ? 5000 : 3000; // Longer delay to allow user to see instructions
    
    setTimeout(function() {
        window.location.href = redirectUrl;
    }, delay);
})();
</script>

<?php
// Store transaction details in session for callback verification if needed
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['mpesa_transaction_' . $txRef] = [
    'invoice_id' => $booking['invoice_id'],
    'transaction_id' => $transactionId,
    'conversation_id' => $conversationId,
    'amount' => $amount,
    'currency' => $currency,
    'phone' => $customerPhone,
    'timestamp' => time()
];
?>

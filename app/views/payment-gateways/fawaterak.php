<?php

// ============================================================================
// FAWATERAK PAYMENT GATEWAY - REDIRECT CHECKOUT
// ============================================================================
// HANDLES SECURE PAYMENT PROCESSING VIA FAWATERAK API
// CREATES AN INVOICE LINK VIA FAWATERAK API AND REDIRECTS USER TO CHECKOUT
// SUPPORTS MULTIPLE CURRENCIES: USD, EGP, SR, AED, KWD, QAR, BHD
// API DOCS: https://docs.fawaterk.com/payment/send-payment
// ============================================================================

/*
Fawaterak Test Credentials:
API Key: d83a5d07aaeb8442dcbe259e6dae80a3f2e21a3a581e1a5acd
Staging URL: https://staging.fawaterk.com/api/v2/createInvoiceLink
Production URL: https://fawaterk.com/api/v2/createInvoiceLink
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
    if (!$payload) {
        echo '<p style="color:red">Invalid payment payload.</p>';
        return;
    }

    $booking = [
        'invoice_id' => (string)($payload->booking_ref_no ?? $payload->invoice_id ?? 'N/A'),
        'email' => (string)($payload->client_email ?? 'customer@example.com'),
        'phone' => (string)($payload->client_phone ?? '0000000000'),
        'first_name' => (string)($payload->first_name ?? 'Customer'),
        'last_name' => (string)($payload->last_name ?? 'User'),
        'price_markup' => (float)($payload->price ?? 0),
        'currency_markup' => (string)($payload->currency ?? 'USD'),
        'address' => (string)($payload->address ?? ''),
        'items' => is_array($payload->items ?? null) ? $payload->items : [],
    ];

    $token = $_POST['payment_token'] ?? $_POST['payload'];
    $tokenParam = urlencode($token ?? '');
    $successUrl = $_POST['success_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . $tokenParam . '&payment_status=success');
    $cancelUrl = $_POST['cancel_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . $tokenParam . '&payment_status=cancel');
    $pendingUrl = $_POST['pending_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . $tokenParam . '&payment_status=pending');

    // RESOLVE GATEWAY CONFIGURATION
    if (isset($gateway) && is_array($gateway)) {
        $config = get_gateway_config($gateway);
    } else {
        global $db;
        $gateway = $db->get('payment_gateways', '*', ['name' => 'Fawaterak', 'status' => 1]);
        $config = get_gateway_config($gateway ?: []);
    }
} else {
    // NEW PAYMENT LIBRARY FORMAT
    $booking = $paymentData['booking'];
    $gateway = $paymentData['gateway'];
    $successUrl = $paymentData['success_url'];
    $cancelUrl = $paymentData['cancel_url'];
    $pendingUrl = $paymentData['pending_url'] ?? $successUrl;
    $config = get_gateway_config($gateway);
}

// ============================================================================
// EXTRACT FAWATERAK CREDENTIALS
// ============================================================================
// api_key = Bearer token for authorization
// ============================================================================
$apiKey = $config['api_key'] ?? $gateway['c1'] ?? '';

if (empty($apiKey)) {
    echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px">';
    echo '<p style="color:red"><strong>Fawaterak Configuration Error</strong></p>';
    echo '<p style="color:#666">API Key not configured.</p>';
    if (!empty($config['dev_mode']) || !empty($gateway['dev_mode'])) {
        echo '<p style="font-size:12px;color:#999">Debug - Config keys: ' . implode(', ', array_keys($config)) . '</p>';
    }
    echo '</div>';
    return;
}

// ============================================================================
// DETERMINE ENVIRONMENT (STAGING vs PRODUCTION)
// ============================================================================
$isDevMode = !empty($config['dev_mode']) || !empty($gateway['dev_mode']);
$baseUrl = $isDevMode
    ? 'https://staging.fawaterk.com/api/v2'
    : 'https://fawaterk.com/api/v2';

// ============================================================================
// VALIDATE AND PREPARE PAYMENT DATA
// ============================================================================
$amount = round((float)$booking['price_markup'], 2);
$currency = strtoupper($booking['currency_markup'] ?? 'USD');

// Fawaterak supported currencies
$supportedCurrencies = ['USD', 'EGP', 'SR', 'AED', 'KWD', 'QAR', 'BHD'];
if (!in_array($currency, $supportedCurrencies)) {
    $currency = 'USD'; // Fallback to USD if currency not supported
}

// Extract customer information
$firstName = $booking['first_name'] ?? 'Customer';
$lastName = $booking['last_name'] ?? 'User';
$email = $booking['email'] ?? 'customer@example.com';
$phone = preg_replace('/[^0-9]/', '', $booking['phone'] ?? '0000000000') ?: '0000000000';
$address = $booking['address'] ?? '';

// Validate amount
if ($amount < 0.01) {
    echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px">';
    echo '<p style="color:red">Invalid payment amount. Minimum is 0.01</p>';
    echo '</div>';
    return;
}

// ============================================================================
// PREPARE CART ITEMS
// ============================================================================
// If items are provided, use them; otherwise create a single item for the total
$cartItems = [];

if (!empty($booking['items']) && is_array($booking['items'])) {
    // Use provided items
    foreach ($booking['items'] as $item) {
        $cartItems[] = [
            'name' => $item['name'] ?? 'Product',
            'price' => (string)round((float)($item['price'] ?? 0), 2),
            'quantity' => (int)($item['quantity'] ?? 1)
        ];
    }
} else {
    // Create single item from total amount
    $cartItems[] = [
        'name' => 'Travel Booking - Invoice ' . $booking['invoice_id'],
        'price' => (string)$amount,
        'quantity' => 1
    ];
}

// Validate cart items
if (empty($cartItems)) {
    echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px">';
    echo '<p style="color:red">No items in cart.</p>';
    echo '</div>';
    return;
}

// ============================================================================
// BUILD FAWATERAK REQUEST PAYLOAD
// ============================================================================
try {
    $requestData = [
        'cartTotal' => (string)$amount,
        'currency' => $currency,
        'customer' => [
            'first_name' => substr($firstName, 0, 100),
            'last_name' => substr($lastName, 0, 100),
            'email' => $email,
            'phone' => $phone,
            'address' => substr($address, 0, 200),
        ],
        'cartItems' => $cartItems,
        'redirectionUrls' => [
            'successUrl' => $successUrl,
            'failUrl' => $cancelUrl,
            'pendingUrl' => $pendingUrl,
        ],
    ];

    // Add optional payload with invoice reference
    $requestData['payLoad'] = [
        'invoice_id' => $booking['invoice_id'],
        'booking_ref' => $booking['invoice_id'],
        'timestamp' => time(),
    ];

    // ============================================================================
    // CREATE FAWATERAK INVOICE LINK VIA API
    // ============================================================================
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl . '/createInvoiceLink');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
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
    if ($httpCode !== 200 || !is_array($result) || empty($result['data']['url'])) {
        // Extract error message safely
        $errorMsg = 'Unknown error occurred';

        if (is_array($result)) {
            // Try to get error message from various possible fields
            if (!empty($result['message'])) {
                if (is_string($result['message'])) {
                    // Simple string message
                    $errorMsg = $result['message'];
                } elseif (is_array($result['message']) || is_object($result['message'])) {
                    // Nested object/array with error details (e.g., {"token": ["Invalid Token..."]})
                    $messageArray = (array)$result['message'];
                    $errorParts = [];
                    
                    foreach ($messageArray as $field => $errors) {
                        if (is_array($errors)) {
                            $errorParts = array_merge($errorParts, array_filter(array_map('strval', $errors)));
                        } elseif (is_string($errors)) {
                            $errorParts[] = $errors;
                        } elseif (is_object($errors)) {
                            $errorParts[] = json_encode($errors);
                        }
                    }
                    
                    if (!empty($errorParts)) {
                        $errorMsg = implode('; ', $errorParts);
                    }
                }
            } elseif (!empty($result['type']) && is_string($result['type'])) {
                $errorMsg = $result['type'];
            } elseif (!empty($result['error']) && is_string($result['error'])) {
                $errorMsg = $result['error'];
            } elseif (!empty($result['errors']) && is_array($result['errors'])) {
                // Handle errors array
                $errorMsg = implode(', ', array_filter(array_map('strval', $result['errors'])));
            }
        } elseif (!empty($response)) {
            // If response is not JSON, use first 200 chars
            $errorMsg = substr(strip_tags($response), 0, 200);
        }

        echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px">';
        echo '<p style="color:red"><strong>Payment initialization failed:</strong><br>' . htmlspecialchars((string)$errorMsg) . '</p>';
        echo '<p style="font-size:12px;color:#666">HTTP Code: ' . htmlspecialchars((string)$httpCode) . '</p>';

        // Provide helpful guidance for common errors
        if (stripos($errorMsg, 'Invalid Token') !== false || stripos($errorMsg, 'inactive vendor') !== false) {
            echo '<div style="background:#fef3cd;border:1px solid #ffc107;border-radius:5px;padding:10px;margin-top:10px;font-size:12px;color:#856404">';
            echo '<strong>Troubleshooting:</strong><br>';
            echo '• Verify your Fawaterak API key is correct<br>';
            echo '• Ensure your Fawaterak vendor account is active<br>';
            echo '• Check that the API key is configured in payment gateway settings<br>';
            echo '• Contact Fawaterak support if the account is suspended<br>';
            echo '</div>';
        }

        if ($isDevMode) {
            if (is_array($result)) {
                echo '<pre style="background:#f5f5f5;padding:10px;font-size:11px;overflow:auto;max-height:200px">' . htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT)) . '</pre>';
            } else {
                echo '<pre style="background:#f5f5f5;padding:10px;font-size:11px;overflow:auto;max-height:200px">' . htmlspecialchars(substr($response, 0, 500)) . '</pre>';
            }
            echo '<details style="margin-top:10px"><summary style="cursor:pointer;font-size:12px;color:#999">Request Debug</summary>';
            echo '<pre style="background:#f5f5f5;padding:10px;font-size:11px;overflow:auto">' . htmlspecialchars(json_encode($requestData, JSON_PRETTY_PRINT)) . '</pre>';
            echo '</details>';
        }

        echo '</div>';
        return;
    }

    // ============================================================================
    // INVOICE LINK CREATED SUCCESSFULLY - PREPARE REDIRECT
    // ============================================================================
    $checkoutUrl = $result['data']['url'];
    $invoiceKey = $result['data']['invoiceKey'] ?? '';
    $invoiceId = $result['data']['invoiceId'] ?? '';

} catch (\Throwable $e) {
    error_log("FAWATERAK_PAYMENT_ERROR: " . $e->getMessage());
    echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px">';
    echo '<p style="color:red">Payment processing error. Please try again.</p>';
    if ($isDevMode) {
        echo '<p style="font-size:12px;color:#999">' . htmlspecialchars($e->getMessage()) . '</p>';
    }
    echo '</div>';
    return;
}

// ============================================================================
// STORE TRANSACTION METADATA (optional - for payment verification)
// ============================================================================
try {
    if (function_exists('log_payment_attempt')) {
        // log_payment_attempt([
        //     'gateway' => 'fawaterak',
        //     'invoice_id' => $booking['invoice_id'] ?? null,
        //     'invoice_key' => $invoiceKey,
        //     'fawaterak_invoice_id' => $invoiceId,
        //     'amount' => $amount,
        //     'currency' => $currency,
        //     'email' => $email,
        //     'timestamp' => time(),
        // ]);
    }
} catch (\Throwable $e) {
    // Log but don't fail payment
    error_log("FAWATERAK_LOGGING_ERROR: " . $e->getMessage());
}

?>

<!-- ============================================================================ -->
<!-- FAWATERAK CHECKOUT - REDIRECT PAYMENT PAGE -->
<!-- ============================================================================ -->
<style>
.fawaterak-container {
    max-width: 420px;
    margin: 0 auto;
    padding: 20px;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
    text-align: center;
}

.faw-btn {
    background: #FF6D00;
    color: white;
    border: none;
    padding: 15px 30px;
    border-radius: 8px;
    font-size: 16px;
    cursor: pointer;
    width: 100%;
    font-weight: 600;
    text-decoration: none;
    display: inline-block;
    transition: background 0.2s;
    margin-top: 10px;
}

.faw-btn:hover {
    background: #E55100;
}

.faw-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.faw-info {
    background: #f8fafc;
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 15px;
    text-align: left;
}

.faw-info p {
    margin: 5px 0;
    font-size: 14px;
    color: #334155;
}

.faw-info strong {
    color: #0f172a;
}

.faw-spinner {
    display: inline-block;
    width: 18px;
    height: 18px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-radius: 50%;
    border-top-color: #fff;
    animation: faw-spin 0.6s linear infinite;
    vertical-align: middle;
    margin-left: 8px;
}

@keyframes faw-spin {
    to { transform: rotate(360deg); }
}
</style>

<div class="fawaterak-container">
    <div class="faw-info">
        <p><strong>Invoice:</strong> <?= htmlspecialchars((string)($booking['invoice_id'] ?? 'N/A')) ?></p>
        <p><strong>Amount:</strong> <?= htmlspecialchars((string)($currency ?? 'USD') . ' ' . number_format((float)($amount ?? 0), 2)) ?></p>
        <p><strong>Email:</strong> <?= htmlspecialchars((string)($email ?? 'customer@example.com')) ?></p>
    </div>

    <button type="button" id="faw-pay-btn" class="faw-btn" onclick="doFawaterakCheckout()">
        <span id="faw-btn-text">Pay <?= htmlspecialchars((string)($currency ?? 'USD') . ' ' . number_format((float)($amount ?? 0), 2)) ?></span>
        <span id="faw-btn-spinner" class="faw-spinner" style="display:none"></span>
    </button>

    <p style="margin-top:15px;font-size:12px;color:#94a3b8">Powered by Fawaterak</p>
</div>

<script>
(function() {
    const checkoutUrl = <?= json_encode($checkoutUrl) ?>;
    const isDevMode = <?= json_encode($isDevMode) ?>;
    const invoiceId = <?= json_encode($booking['invoice_id']) ?>;

    window.doFawaterakCheckout = function() {
        const btn = document.getElementById('faw-pay-btn');
        const btnText = document.getElementById('faw-btn-text');
        const btnSpinner = document.getElementById('faw-btn-spinner');

        if (btn) {
            btn.disabled = true;
            btnText.textContent = 'Redirecting...';
            btnSpinner.style.display = 'inline-block';
        }

        // Redirect to Fawaterak checkout
        window.location.href = checkoutUrl;
    };

    // Auto-trigger payment after delay
    function initFawaterakPayment() {
        const delay = isDevMode ? 1500 : 800;
        setTimeout(function() {
            doFawaterakCheckout();
        }, delay);
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFawaterakPayment);
    } else {
        initFawaterakPayment();
    }
})();
</script>

<?php
// Render payment button based on format (for consistency with Stripe/PayPal)
if (isset($_POST['payload'])) {
    // Legacy format - session handling
    $rand = date('Ymdhis') . rand();
    $_SESSION['bookingkey'] = $rand;
} elseif (isset($paymentData)) {
    // New format - use helper function if available
    if (function_exists('render_payment_button')) {
        echo render_payment_button($gateway, $booking, 'doFawaterakCheckout()', 'Pay with Fawaterak');
    }
}
?>

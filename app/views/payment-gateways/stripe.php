<?php

// ============================================================================
// STRIPE PAYMENT GATEWAY - INTEGRATED CHECKOUT
// ============================================================================
// HANDLES SECURE PAYMENT PROCESSING VIA STRIPE CHECKOUT SESSION
// SUPPORTS BOTH LEGACY PAYLOAD FORMAT AND NEW PAYMENT LIBRARY FORMAT
// REDIRECTS USERS TO STRIPE HOSTED CHECKOUT PAGE FOR CARD PROCESSING
// ============================================================================

// CHECK IF PAYMENT DATA EXISTS - EXIT IF NO PAYMENT REQUEST DETECTED
if (!isset($_POST['payload']) && !isset($paymentData)) {
    return;
}

// ============================================================================
// LOAD STRIPE SDK - REQUIRED FOR API COMMUNICATION
// ============================================================================
if (!class_exists(\Stripe\Stripe::class)) {
    $autoload = __DIR__ . '/../../../vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }
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
    $booking = [
        'invoice_id' => $payload->booking_ref_no ?? $payload->invoice_id,
        'email' => $payload->client_email,
        'price_markup' => $payload->price,
        'currency_markup' => $payload->currency,
        'ref' => $payload->booking_ref_no ?? $payload->invoice_id
    ];
    $token = $paymentData['token'] ?? ($_POST['payment_token'] ?? $_POST['payload']);
    $tokenParam = urlencode($token);
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
        $gateway = $db->get('payment_gateways', '*', ['name' => 'Stripe', 'status' => 1]);
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
// INITIALIZE STRIPE API WITH SECRET KEY
// ============================================================================
$secretKey = $config['secret_key'] ?? '';
if (empty($secretKey)) {
    echo '<div style="text-align:center;padding:20px;color:#ef4444;">';
    echo '<p><strong>Configuration Error</strong></p>';
    echo '<p>Stripe secret key is not configured. Please contact support.</p>';
    echo '</div>';
    return;
}

\Stripe\Stripe::setApiKey($secretKey);

// ============================================================================
// FORMAT AMOUNT IN CENTS - STRIPE REQUIRES INTEGER AMOUNTS IN SMALLEST UNIT
// ============================================================================
$amount = intval($booking['price_markup'] * 100);

// Validate amount
if ($amount <= 0) {
    echo '<div style="text-align:center;padding:20px;color:#ef4444;">';
    echo '<p>Invalid payment amount. Please contact support.</p>';
    echo '</div>';
    return;
}

$successUrlWithSession = $successUrl;
if (strpos($successUrlWithSession, '{CHECKOUT_SESSION_ID}') === false) {
    $separator = strpos($successUrlWithSession, '?') === false ? '?' : '&';
    $successUrlWithSession .= $separator . 'session_id={CHECKOUT_SESSION_ID}&transaction_id={CHECKOUT_SESSION_ID}';
}

try {
    // ============================================================================
    // CREATE STRIPE CHECKOUT SESSION - HOSTED PAYMENT PAGE
    // ============================================================================
    $session = \Stripe\Checkout\Session::create([
        'customer_email' => $booking['email'],
        'payment_method_types' => ['card'],
        'mode' => 'payment',
        // SECURITY (P1): bind the session to this invoice so verification can
        // confirm the paid session belongs to this booking (not another).
        'client_reference_id' => (string) $booking['invoice_id'],
        'metadata' => ['invoice_id' => (string) $booking['invoice_id']],
        'line_items' => [
            [
                'price_data' => [
                    'currency' => strtolower($booking['currency_markup']),
                    'unit_amount' => $amount,
                    'product_data' => [
                        'name' => 'Travel Booking',
                        'description' => 'Booking for Invoice ' . $booking['invoice_id'],
                    ],
                ],
                'quantity' => 1,
            ]
        ],
        'success_url' => $successUrlWithSession,
        'cancel_url' => $cancelUrl,
    ]);

    // Validate session was created
    if (empty($session->url)) {
        throw new Exception('Failed to create Stripe checkout session');
    }

    ?>

    <!-- ============================================================================ -->
    <!-- STRIPE REDIRECT - AUTO-REDIRECT TO CHECKOUT PAGE -->
    <!-- ============================================================================ -->
    <div style="text-align:center;padding:20px;color:#64748b;">
        <p style="margin-bottom:12px;">Redirecting to Stripe Checkout...</p>
        <div class="spinner" style="margin:0 auto;width:40px;height:40px;border:4px solid #e5e7eb;border-top-color:#635bff;border-radius:50%;animation:spin 0.8s linear infinite;"></div>
    </div>
    
    <script>
        // Auto-redirect to Stripe checkout URL
        (function() {
            const checkoutUrl = <?= json_encode($session->url) ?>;
            const isDevMode = <?= json_encode(!empty($config['dev_mode'])) ?>;
            const delay = isDevMode ? 1000 : 500;
            
            setTimeout(function() {
                window.location.href = checkoutUrl;
            }, delay);
        })();
    </script>

    <style>
        .payment-container {
            max-width: 400px;
            margin: 0 auto;
            padding: 20px;
            text-align: center;
        }

        .stripe-btn {
            background: #635bff;
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            width: 100%;
            margin-top: 10px;
        }

        .stripe-btn:hover {
            background: #5a54d9;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>

    <?php

} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px;border:1px solid #fecaca;">';
    echo '<h4 style="color:#dc2626;margin-top:0;">Stripe Payment Error</h4>';
    echo '<p style="color:#991b1b;margin-bottom:10px;">' . htmlspecialchars($errorMsg) . '</p>';
    
    // Audit: do NOT render key-presence / amount / email debug to the browser.
    error_log('Stripe error for invoice ' . ($booking['invoice_id'] ?? '?') . ': ' . $errorMsg
        . ' (secret ' . (empty($config['secret_key']) ? 'MISSING' : 'present') . ')');

    echo '<p style="margin-top:15px;"><a href="' . htmlspecialchars(root . 'invoice/' . $booking['invoice_id']) . '" style="color:#2563eb;">← Return to Invoice</a></p>';
    echo '</div>';
}


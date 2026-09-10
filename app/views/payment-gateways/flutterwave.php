<?php

// ============================================================================
// FLUTTERWAVE PAYMENT GATEWAY - INLINE CHECKOUT INTEGRATION (V3)
// ============================================================================
// HANDLES SECURE PAYMENT PROCESSING VIA FLUTTERWAVE INLINE MODAL
// SUPPORTS BOTH LEGACY PAYLOAD FORMAT AND NEW PAYMENT LIBRARY FORMAT
// PROVIDES SEAMLESS IN-PAGE CHECKOUT WITH MULTIPLE PAYMENT OPTIONS
// SUPPORTS: CARDS, MOBILE MONEY, USSD, BANK TRANSFERS
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
        'price_markup' => $payload->price,
        'currency_markup' => $payload->currency,
        'ref' => $payload->booking_ref_no ?? $payload->invoice_id
    ];

    $token = $paymentData['token'] ?? ($_POST['payment_token'] ?? $_POST['payload']);
    $tokenParam = urlencode($token ?? '');
    $successUrl = $_POST['success_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . $tokenParam . '&payment_status=success');
    $cancelUrl = $_POST['cancel_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . $tokenParam . '&payment_status=cancel');
    $failureUrl = $_POST['failure_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . $tokenParam . '&payment_status=failure');

    // RESOLVE GATEWAY CONFIGURATION FROM MULTIPLE SOURCES
    if (isset($paymentData) && isset($paymentData['gateway']) && is_array($paymentData['gateway'])) {
        $gateway = $paymentData['gateway'];
        $config = get_gateway_config($gateway);
    } elseif (isset($gateway) && is_array($gateway)) {
        // $gateway can be injected by the route including this file
        $config = get_gateway_config($gateway);
    } else {
        global $db;
        $gateway = $db->get('payment_gateways', '*', ['name' => 'Flutterwave', 'status' => 1]);
        $config = get_gateway_config($gateway ?: []);
    }
} elseif (isset($paymentData)) {
    // NEW PAYMENT LIBRARY FORMAT - STRUCTURED DATA ARRAY
    $booking = $paymentData['booking'];
    $gateway = $paymentData['gateway'];
    $token = $paymentData['token'];
    $successUrl = $paymentData['success_url'];
    $cancelUrl = $paymentData['cancel_url'];
    $failureUrl = $paymentData['failure_url'];

    // EXTRACT GATEWAY CONFIGURATION USING HELPER FUNCTION
    $config = get_gateway_config($gateway);
} else {
    return;
}

// ============================================================================
// VALIDATE FLUTTERWAVE PUBLIC KEY - REQUIRED FOR SDK INITIALIZATION
// ============================================================================
$publicKey = $config['api_key'] ?? $config['public_key'] ?? '';

if (empty($publicKey)) {
    echo '<p style="color:red">Flutterwave is not configured. Please contact support.</p>';
    return;
}

// ============================================================================
// FORMAT AMOUNT AND CURRENCY FOR FLUTTERWAVE API
// FLUTTERWAVE ACCEPTS DECIMAL AMOUNTS (NOT CENTS)
// ============================================================================
$amount = number_format((float) $booking['price_markup'], 2, '.', '');
$currency = strtoupper($booking['currency_markup'] ?? 'USD');

// ============================================================================
// GENERATE UNIQUE TRANSACTION REFERENCE - MUST BE UNIQUE PER TRANSACTION
// ============================================================================
$txRef = 'FLW-' . $booking['invoice_id'] . '-' . time();

// ============================================================================
// EXTRACT CUSTOMER INFORMATION FOR CHECKOUT
// ============================================================================
$customerEmail = $booking['email'] ?? 'customer@example.com';
$customerName = $booking['name'] ?? 'Customer';

?>

<style>
    .flutterwave-container {
        max-width: 420px;
        margin: 0 auto;
        padding: 20px;
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
    }

    .flutterwave-test-creds {
        background: #f8fafc;
        border: 1px dashed #cbd5f5;
        border-radius: 10px;
        padding: 14px;
        margin-bottom: 18px;
    }

    .flw-btn {
        background: #f5a623;
        color: white;
        border: none;
        padding: 15px 30px;
        border-radius: 5px;
        font-size: 16px;
        cursor: pointer;
        width: 100%;
        margin-top: 10px;
        font-weight: bold;
        transition: background 0.3s ease;
    }

    .flw-btn:hover {
        background: #e09612;
    }

    .flw-btn:disabled {
        background: #cbd5e0;
        cursor: not-allowed;
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
</style>

<div class="flutterwave-container">
    <div class="payment-info">
        <p><strong>Invoice:</strong> <?= htmlspecialchars($booking['invoice_id']) ?></p>
        <p><strong>Amount:</strong> <?= htmlspecialchars($currency) ?> <?= htmlspecialchars($amount) ?></p>
        <p><strong>Email:</strong> <?= htmlspecialchars($customerEmail) ?></p>
    </div>

    <button id="flutterwave-pay-button" class="flw-btn">
        Pay with Flutterwave - <?= htmlspecialchars($currency) ?> <?= htmlspecialchars($amount) ?>
    </button>
</div>

<!-- ============================================================================ -->
<!-- FLUTTERWAVE INLINE JAVASCRIPT SDK (V3) - CLIENT-SIDE CHECKOUT MODAL -->
<!-- ============================================================================ -->
<script src="https://checkout.flutterwave.com/v3.js"></script>

<script>
(function() {
    // ============================================================================
    // DEFINE CALLBACK URLs FOR PAYMENT OUTCOMES
    // ============================================================================
    const flwSuccessUrl = <?= json_encode($successUrl) ?>;
    const flwCancelUrl = <?= json_encode($cancelUrl) ?>;
    const flwFailureUrl = <?= json_encode($failureUrl) ?>;

    // ============================================================================
    // BUILD REDIRECT URL WITH QUERY PARAMETERS
    // ============================================================================
    function buildRedirectUrl(url, params = {}) {
        const target = new URL(url, window.location.origin);
        Object.entries(params).forEach(([key, value]) => {
            if (value !== undefined && value !== null && value !== '') {
                target.searchParams.set(key, value);
            }
        });
        return target.toString();
    }

    // ============================================================================
    // INITIALIZE FLUTTERWAVE PAYMENT MODAL - LAUNCH CHECKOUT
    // ============================================================================
    function makeFlutterwavePayment() {
        const button = document.getElementById('flutterwave-pay-button');
        if (button) {
            button.disabled = true;
            button.textContent = 'Processing...';
        }

        if (typeof FlutterwaveCheckout === 'undefined') {
            console.error('FlutterwaveCheckout not loaded');
            if (button) {
                button.disabled = false;
                button.textContent = 'Pay with Flutterwave - <?= htmlspecialchars($currency) ?> <?= htmlspecialchars($amount) ?>';
            }
            alert('Payment system not loaded. Please refresh and try again.');
            return;
        }

        FlutterwaveCheckout({
            public_key: "<?= htmlspecialchars($publicKey) ?>",
            tx_ref: "<?= htmlspecialchars($txRef) ?>",
            amount: <?= json_encode($amount) ?>,
            currency: "<?= htmlspecialchars($currency) ?>",
            payment_options: "card,mobilemoney,ussd,banktransfer",
            customer: {
                email: "<?= htmlspecialchars($customerEmail) ?>",
                name: "<?= htmlspecialchars($customerName) ?>",
            },
            customizations: {
                title: "Travel Booking Payment",
                description: "Payment for Invoice <?= htmlspecialchars($booking['invoice_id']) ?>",
                logo: "<?= root ?>assets/img/logo.png",
            },
            callback: function (data) {
                console.log('Flutterwave payment callback:', data);

                // Payment was successful
                if (data.status === 'successful' || data.status === 'completed') {
                    const transactionId = data.transaction_id || data.tx_ref;
                    window.location.href = buildRedirectUrl(flwSuccessUrl, {
                        transaction_id: transactionId,
                        tx_ref: data.tx_ref || "<?= htmlspecialchars($txRef) ?>",
                        gateway: 'flutterwave',
                        payment_status: 'success'
                    });
                } else {
                    window.location.href = buildRedirectUrl(flwFailureUrl, {
                        error: 'payment_failed',
                        gateway: 'flutterwave',
                        payment_status: 'failure'
                    });
                }
            },
            onclose: function () {
                console.log('Flutterwave payment modal closed');
                
                if (button) {
                    button.disabled = false;
                    button.textContent = 'Pay with Flutterwave - <?= htmlspecialchars($currency) ?> <?= htmlspecialchars($amount) ?>';
                }

                // Redirect to cancel URL
                window.location.href = buildRedirectUrl(flwCancelUrl, {
                    error: 'user_cancelled',
                    gateway: 'flutterwave',
                    payment_status: 'cancel'
                });
            }
        });
    }

    // Wait for DOM and Flutterwave SDK to be ready
    function initFlutterwave() {
        const payButton = document.getElementById('flutterwave-pay-button');
        if (!payButton) {
            console.error('Flutterwave button not found');
            return;
        }

        payButton.addEventListener('click', function() {
            makeFlutterwavePayment();
        });

        // AUTO-TRIGGER: Launch payment modal automatically
        const isDevMode = <?= json_encode(!empty($config['dev_mode'])) ?>;
        const delay = isDevMode ? 1500 : 500;

        setTimeout(function() {
            makeFlutterwavePayment();
        }, delay);
    }

    // Initialize when script loads (works for AJAX-loaded content)
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFlutterwave);
    } else {
        initFlutterwave();
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
        echo render_payment_button($gateway, $booking, 'makeFlutterwavePayment()', 'Pay with Flutterwave');
    }
}
?>
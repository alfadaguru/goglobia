<?php

// ============================================================================
// PAYPAL PAYMENT GATEWAY - SMART BUTTONS INTEGRATION
// ============================================================================
// HANDLES SECURE PAYMENT PROCESSING VIA PAYPAL SMART BUTTONS SDK
// SUPPORTS BOTH LEGACY PAYLOAD FORMAT AND NEW PAYMENT LIBRARY FORMAT
// PROVIDES IN-PAGE CHECKOUT EXPERIENCE WITHOUT EXTERNAL REDIRECTS
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
        'price_markup' => $payload->price,
        'currency_markup' => $payload->currency,
        'email' => $payload->client_email
    ];

    $token = $paymentData['token'] ?? ($_POST['payment_token'] ?? $_POST['payload']);
    $tokenParam = urlencode($token ?? '');
    $successUrl = $_POST['success_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . $tokenParam . '&payment_status=success');
    $cancelUrl = $_POST['cancel_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . $tokenParam . '&payment_status=cancel');
    $failureUrl = $_POST['failure_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . $tokenParam . '&payment_status=failure');

    // RESOLVE GATEWAY CONFIGURATION FROM MULTIPLE SOURCES
    if (isset($paymentData['gateway'])) {
        $gateway = $paymentData['gateway'];
    } elseif (isset($gateway) && is_array($gateway)) {
        // GATEWAY INJECTED BY CALLER
    } else {
        global $db;
        $gateway = $db->get('payment_gateways', '*', ['name' => 'PayPal', 'status' => 1]);
    }
    $config = get_gateway_config($gateway ?: []);
} elseif (isset($paymentData)) {
    // NEW PAYMENT LIBRARY FORMAT - STRUCTURED DATA ARRAY
    $booking = $paymentData['booking'];
    $gateway = $paymentData['gateway'];
    $token = $paymentData['token'];
    $successUrl = $paymentData['success_url'];
    $cancelUrl = $paymentData['cancel_url'];
    $failureUrl = $paymentData['failure_url'];
    $config = get_gateway_config($gateway);
} else {
    return;
}

// ============================================================================
// VALIDATE PAYPAL CLIENT ID - REQUIRED FOR SDK INITIALIZATION
// ============================================================================
$clientId = $config['api_key'] ?? $config['username'] ?? '';

if (empty($clientId)) {
    echo '<p style="color:red">PayPal is not configured. Please contact support.</p>';
    return;
}

// ============================================================================
// FORMAT AMOUNT AND CURRENCY FOR PAYPAL API
// ============================================================================
$amount = number_format((float)$booking['price_markup'], 2, '.', '');
$currency = strtoupper($booking['currency_markup'] ?? 'USD');

?>

<style>
    .paypal-container {
        max-width: 420px;
        margin: 0 auto;
        padding: 20px;
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
    }
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
</style>

<div class="paypal-container">
    <div id="paypal-button-container">
        <div style="text-align:center;padding:20px;color:#64748b;">
            <p>Loading PayPal...</p>
            <div style="margin:15px auto;width:40px;height:40px;border:4px solid #e5e7eb;border-top-color:#0070ba;border-radius:50%;animation:spin 0.8s linear infinite;"></div>
        </div>
    </div>
</div>

<!-- ============================================================================ -->
<!-- PAYPAL JAVASCRIPT SDK - SMART BUTTONS CLIENT-SIDE INTEGRATION -->
<!-- ============================================================================ -->
<script>
(function() {
    // ============================================================================
    // DEFINE CALLBACK URLs FOR PAYMENT OUTCOMES
    // ============================================================================
    const paypalSuccessUrl = <?= json_encode($successUrl) ?>;
    const paypalCancelUrl = <?= json_encode($cancelUrl) ?>;
    const paypalFailureUrl = <?= json_encode($failureUrl) ?>;

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
    // LOAD PAYPAL SDK AND INITIALIZE BUTTONS
    // ============================================================================
    function loadPayPalSDK() {
        const container = document.getElementById('paypal-button-container');
        
        // Check if PayPal SDK is already loaded globally
        if (window.paypal) {
            container.innerHTML = ''; // Clear loading message
            initPayPalButtons();
            return;
        }

        // Check if script is already being loaded
        const existingScript = document.querySelector('script[src*="paypal.com/sdk"]');
        if (existingScript) {
            existingScript.addEventListener('load', function() {
                container.innerHTML = ''; // Clear loading message
                initPayPalButtons();
            });
            return;
        }

        // Load PayPal SDK dynamically
        const script = document.createElement('script');
        script.src = 'https://www.paypal.com/sdk/js?client-id=<?= htmlspecialchars($clientId) ?>&currency=<?= htmlspecialchars($currency) ?>&disable-funding=credit,card';
        script.onload = function() {
            container.innerHTML = ''; // Clear loading message
            initPayPalButtons();
        };
        script.onerror = function() {
            container.innerHTML = '<p style="color:red;text-align:center;">Failed to load PayPal. Please try again.</p>';
        };
        document.head.appendChild(script);
    }

    function initPayPalButtons() {
        if (!window.paypal) {
            console.error('PayPal SDK not loaded');
            return;
        }

        const container = document.getElementById('paypal-button-container');
        if (!container) {
            console.error('PayPal container not found');
            return;
        }

        paypal.Buttons({
            style: {
                layout: 'vertical',
                color: 'gold',
                shape: 'rect',
                label: 'paypal'
            },
            createOrder: function(data, actions) {
                return actions.order.create({
                    purchase_units: [{
                        amount: { value: '<?= $amount ?>' }
                    }]
                });
            },
            onApprove: function(data, actions) {
                return actions.order.capture().then(function(details) {
                    const trxId = details.id || data.orderID;
                    window.location.href = buildRedirectUrl(paypalSuccessUrl, {
                        transaction_id: trxId,
                        gateway: 'paypal'
                    });
                }).catch(function(err) {
                    console.error('PayPal capture error', err);
                    window.location.href = buildRedirectUrl(paypalFailureUrl, {
                        error: 'capture_failed',
                        gateway: 'paypal'
                    });
                });
            },
            onCancel: function(data) {
                window.location.href = buildRedirectUrl(paypalCancelUrl, {
                    error: 'user_cancelled',
                    gateway: 'paypal'
                });
            },
            onError: function(err) {
                console.error('PayPal error', err);
                window.location.href = buildRedirectUrl(paypalFailureUrl, {
                    error: 'gateway_error',
                    gateway: 'paypal'
                });
            }
        }).render('#paypal-button-container').then(function() {
            // Auto-click PayPal button after it's rendered
            setTimeout(function() {
                const paypalButton = document.querySelector('[data-funding-source="paypal"]');
                if (paypalButton) {
                    paypalButton.click();
                }
            }, 1500); // Wait 1.5 seconds to show the button then auto-click
        }).catch(function(err) {
            console.error('PayPal button render error:', err);
            container.innerHTML = '<p style="color:red;text-align:center;">Failed to render PayPal button. Please refresh and try again.</p>';
        });
    }

    // Start loading PayPal SDK immediately
    loadPayPalSDK();
})();
</script>
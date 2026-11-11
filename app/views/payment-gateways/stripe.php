<?php

// ============================================================================
// STRIPE PAYMENT GATEWAY - ON-DOMAIN ELEMENTS (Payment Element)
// ============================================================================
// Renders Stripe's embedded Payment Element in-page (no off-site redirect) and
// confirms a server-created PaymentIntent in the browser via Stripe.js. The card
// never touches our server (PCI). Supports the legacy base64 payload and the new
// $paymentData array. Verification + refund use the PaymentIntent id.
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
// IDEMPOTENCY GUARD (customer-journey audit #3): block a second real charge for
// an already-paid invoice reached via browser-back / stale-tab replay of the GET
// gateway-loader routes (which bypass process_payment's guard). See paystack.php.
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
// Charge the amount DUE NOW (the loader puts payment_amount_due() into
// $payload->price / $booking['price_markup'] here), in the booking currency.
// MINOR UNITS (zero-decimal fix): Stripe wants the amount in the currency's
// smallest unit — cents for USD/EUR (×100), but ZERO-decimal currencies
// (JPY/KRW/VND/XAF/XOF…) must be sent as-is (×1) and three-decimal ones
// (KWD/BHD/TND) ×1000. A blind ×100 overcharged JPY 100× (and undercharged
// KWD 10×). Reuse the authoritative exponent table in app/lib/adyen.php so we
// keep ONE source of truth. The verify side (verify_gateway_payment 'stripe')
// divides by the SAME exponent — the two MUST stay in lockstep.
if (!function_exists('adyen_to_minor_units')) {
    require_once __DIR__ . '/../../lib/adyen.php';
}
$amount = adyen_to_minor_units((float) $booking['price_markup'], (string) $booking['currency_markup']);

// Validate amount
if ($amount <= 0) {
    echo '<div style="text-align:center;padding:20px;color:#ef4444;">';
    echo '<p>Invalid payment amount. Please contact support.</p>';
    echo '</div>';
    return;
}

// The browser needs the PUBLISHABLE key (c1 = api_key in get_gateway_config).
$publishableKey = $config['api_key'] ?? '';
if (empty($publishableKey)) {
    echo '<div style="text-align:center;padding:20px;color:#ef4444;">';
    echo '<p><strong>Configuration Error</strong></p>';
    echo '<p>Stripe publishable key is not configured. Please contact support.</p>';
    echo '</div>';
    error_log('Stripe: publishable key (c1) missing for invoice ' . ($booking['invoice_id'] ?? '?'));
    return;
}

try {
    // ============================================================================
    // ON-DOMAIN STRIPE ELEMENTS (Payment Element)
    // ----------------------------------------------------------------------------
    // Replaces the old off-site Checkout Session redirect: we create a PaymentIntent
    // server-side (secret key, c2) and render Stripe's embedded Payment Element in
    // THIS page so the customer never leaves the site (parity with the Paystack
    // in-page pop-up). The card is captured in the browser by Stripe.js and confirmed
    // against the PaymentIntent's client_secret — our server never sees card data (PCI).
    //
    // SECURITY: the PaymentIntent is bound to this invoice via metadata.invoice_id +
    // description, and the server-side verify (verify_gateway_payment 'stripe' case)
    // re-retrieves the PaymentIntent, requires status=succeeded, matches amount/currency,
    // and confirms the invoice binding — the browser 'success' is never trusted.
    // On success the browser navigates to success_url carrying the PaymentIntent id as
    // transaction_id (the same param the callback reads), and refund already accepts a
    // PaymentIntent id directly.
    // ============================================================================
    $intent = \Stripe\PaymentIntent::create([
        'amount'   => $amount,
        'currency' => strtolower($booking['currency_markup']),
        // Bind to this invoice so verification can confirm the PI belongs to this booking.
        'metadata' => ['invoice_id' => (string) $booking['invoice_id']],
        'description' => 'Booking for Invoice ' . $booking['invoice_id'],
        'receipt_email' => $booking['email'] ?? null,
        // Let Stripe present the card (and any dashboard-enabled) methods inline.
        'automatic_payment_methods' => ['enabled' => true],
    ]);

    $clientSecret = (string) ($intent->client_secret ?? '');
    if ($clientSecret === '') {
        throw new Exception('Failed to create Stripe payment');
    }

    // success_url already looks like invoice/{type}/{id}?token=...&payment_status=success
    // (built by the loader). We append the verified PaymentIntent id as transaction_id
    // on the browser side after confirmation (see the script below), so the callback's
    // verify step can re-retrieve and confirm this exact PaymentIntent.
    $returnBase = $successUrl;
    ?>

    <!-- ============================================================================ -->
    <!-- STRIPE PAYMENT ELEMENT - ON-DOMAIN CARD FORM (no redirect off-site) -->
    <!-- ============================================================================ -->
    <div id="stripe-pay-wrap" style="max-width:420px;margin:0 auto;">
        <form id="stripe-payment-form">
            <div id="stripe-payment-element"><!-- Stripe injects the card form here --></div>
            <button id="stripe-submit" type="submit"
                    style="margin-top:16px;width:100%;background:#635bff;color:#fff;border:none;padding:13px 20px;border-radius:7px;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px;">
                <span id="stripe-btn-text">Pay <?= htmlspecialchars(strtoupper((string) $booking['currency_markup'])) ?> <?= htmlspecialchars(number_format((float) $booking['price_markup'], 2)) ?></span>
                <span id="stripe-btn-spin" style="display:none;width:15px;height:15px;border:2px solid rgba(255,255,255,0.35);border-top-color:#fff;border-radius:50%;animation:spin 0.6s linear infinite;"></span>
            </button>
            <div id="stripe-error" role="alert" style="margin-top:12px;color:#dc2626;font-size:13px;display:none;"></div>
        </form>
        <p style="margin-top:12px;text-align:center;color:#94a3b8;font-size:11px;">Your card is processed securely by Stripe. We never see your card details.</p>
    </div>

    <script src="https://js.stripe.com/v3/"></script>
    <script>
    (function () {
        // Guard: Stripe.js must be present. If it failed to load, show a clear error.
        if (typeof Stripe === 'undefined') {
            var w = document.getElementById('stripe-error');
            if (w) { w.style.display = 'block'; w.textContent = 'Could not load the secure card form. Please refresh and try again.'; }
            return;
        }
        var stripe = Stripe(<?= json_encode($publishableKey) ?>);
        var clientSecret = <?= json_encode($clientSecret) ?>;
        var returnUrl = <?= json_encode($returnBase) ?>;
        var intentId = <?= json_encode((string) $intent->id) ?>;

        var elements = stripe.elements({ clientSecret: clientSecret });
        var paymentElement = elements.create('payment');
        paymentElement.mount('#stripe-payment-element');

        var form = document.getElementById('stripe-payment-form');
        var btn = document.getElementById('stripe-submit');
        var btnText = document.getElementById('stripe-btn-text');
        var btnSpin = document.getElementById('stripe-btn-spin');
        var errBox = document.getElementById('stripe-error');

        function setBusy(b) {
            btn.disabled = b;
            btnSpin.style.display = b ? 'inline-block' : 'none';
            btnText.style.opacity = b ? '0.7' : '1';
        }
        function showError(msg) {
            errBox.style.display = 'block';
            errBox.textContent = msg || 'Payment could not be completed. Please try again.';
            setBusy(false);
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            errBox.style.display = 'none';
            setBusy(true);

            // On success we go to OUR callback (returnUrl already carries
            // ?token=...&payment_status=success); add the PaymentIntent id so the
            // server-side verify can re-retrieve and confirm this exact payment.
            var sep = returnUrl.indexOf('?') === -1 ? '?' : '&';
            var finalReturn = returnUrl + sep + 'transaction_id=' + encodeURIComponent(intentId) + '&payment_intent=' + encodeURIComponent(intentId);

            stripe.confirmPayment({
                elements: elements,
                confirmParams: { return_url: finalReturn },
                // Stay on-page unless the card genuinely needs a redirect (e.g. 3-DS
                // that cannot be done inline); then Stripe redirects to finalReturn.
                redirect: 'if_required'
            }).then(function (result) {
                if (result.error) {
                    showError(result.error.message);
                    return;
                }
                var pi = result.paymentIntent;
                if (pi && (pi.status === 'succeeded' || pi.status === 'processing' || pi.status === 'requires_capture')) {
                    // Confirmed inline (no redirect needed) — go to our callback ourselves.
                    window.top.location.href = finalReturn;
                } else if (pi) {
                    showError('Payment status: ' + pi.status + '. Please try again.');
                } else {
                    // redirect:'if_required' handled a redirect; nothing to do here.
                }
            }).catch(function (err) {
                showError(err && err.message ? err.message : 'Payment error. Please try again.');
            });
        });
    })();
    </script>

    <style>
        @keyframes spin { to { transform: rotate(360deg); } }
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


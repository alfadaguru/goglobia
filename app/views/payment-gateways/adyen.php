<?php
// ============================================================================
// ADYEN PAYMENT GATEWAY — HOSTED CHECKOUT (Pay by Link)
// ----------------------------------------------------------------------------
// Creates an Adyen payment link (POST /paymentLinks) and redirects the buyer to
// Adyen's hosted payment page. The buyer returns to the invoice with
// ?payment_status=success&gateway=adyen; verify_gateway_payment('adyen') then
// checks the real link status server-side, and the Adyen webhook confirms
// asynchronously. Mirrors the redirect model used by the Stripe gateway.
// ============================================================================

// EXIT IF NO PAYMENT REQUEST DETECTED
if (!isset($_POST['payload']) && !isset($paymentData)) {
    return;
}

require_once __DIR__ . '/../../lib/adyen.php';

// ============================================================================
// RESOLVE BOOKING / GATEWAY / URLS (supports the new $paymentData format and
// the legacy base64 payload, exactly like the other gateway views).
// ============================================================================
$booking    = null;
$gateway    = null;
$token      = null;
$successUrl = null;
$cancelUrl  = null;

if (isset($paymentData) && is_array($paymentData)) {
    $booking    = $paymentData['booking'] ?? null;
    $gateway    = $paymentData['gateway'] ?? null;
    $token      = $paymentData['token'] ?? null;
    $successUrl = $paymentData['success_url'] ?? ($_POST['success_url'] ?? null);
    $cancelUrl  = $paymentData['cancel_url'] ?? ($_POST['cancel_url'] ?? null);
}

if ((!$booking || !$gateway) && isset($_POST['payload'])) {
    $payload = json_decode(base64_decode($_POST['payload']));
    $booking = $booking ?: [
        'invoice_id'      => $payload->booking_ref_no ?? $payload->invoice_id,
        'email'           => $payload->client_email ?? '',
        'price_markup'    => $payload->price ?? 0,
        'currency_markup' => $payload->currency ?? 'USD',
        'ref'             => $payload->booking_ref_no ?? $payload->invoice_id,
    ];
    $token      = $token ?: ($_POST['payment_token'] ?? '');
    $successUrl = $successUrl ?: ($_POST['success_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . urlencode($token) . '&payment_status=success'));
    $cancelUrl  = $cancelUrl ?: ($_POST['cancel_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . urlencode($token) . '&payment_status=cancel'));

    if (!$gateway) {
        global $db;
        $gateway = $db->get('payment_gateways', '*', ['name' => 'Adyen', 'status' => 1]);
    }
}

if (!$booking || !$gateway) {
    echo '<div style="text-align:center;padding:20px;color:#ef4444;"><p>Payment could not be initialized. Please contact support.</p></div>';
    return;
}

$cfg = adyen_config($gateway);

// ============================================================================
// VALIDATE CONFIGURATION
// ============================================================================
$configError = '';
if ($cfg['api_key'] === '')          $configError = 'Adyen API key is not configured.';
elseif ($cfg['merchant_account'] === '') $configError = 'Adyen merchant account is not configured.';
elseif (!$cfg['dev_mode'] && $cfg['live_url_prefix'] === '') $configError = 'Adyen live URL prefix is required for live mode.';

if ($configError !== '') {
    echo '<div style="text-align:center;padding:20px;color:#ef4444;">';
    echo '<p><strong>Configuration Error</strong></p><p>' . htmlspecialchars($configError) . '</p>';
    echo '</div>';
    return;
}

// ============================================================================
// BUILD + SEND THE PAY BY LINK REQUEST
// ============================================================================
$currency = strtoupper((string) ($booking['currency_markup'] ?? 'USD'));
$value    = adyen_to_minor_units($booking['price_markup'] ?? 0, $currency);

if ($value <= 0) {
    echo '<div style="text-align:center;padding:20px;color:#ef4444;"><p>Invalid payment amount. Please contact support.</p></div>';
    return;
}

// Return URL must carry gateway=adyen so the server-side verify runs on return.
$returnUrl = $successUrl . (strpos($successUrl, '?') === false ? '?' : '&') . 'gateway=adyen';

$body = [
    'reference'       => (string) $booking['invoice_id'],
    'amount'          => ['currency' => $currency, 'value' => $value],
    'merchantAccount' => $cfg['merchant_account'],
    'returnUrl'       => $returnUrl,
    'description'     => 'Booking ' . $booking['invoice_id'],
];
if (!empty($booking['email'])) {
    $body['shopperEmail'] = $booking['email'];
}
$countryCode = strtoupper((string) ($booking['country_code'] ?? $booking['nationality'] ?? ''));
if (preg_match('/^[A-Z]{2}$/', $countryCode)) {
    $body['countryCode'] = $countryCode;
}

$res  = adyen_api_request($cfg, 'POST', 'paymentLinks', $body);
$link = $res['json'] ?? null;

if ($res['http_code'] === 200 || $res['http_code'] === 201) {
    $linkUrl = $link['url'] ?? '';
    $linkId  = $link['id'] ?? '';

    if ($linkUrl === '') {
        echo '<div style="text-align:center;padding:20px;color:#ef4444;"><p>Adyen did not return a payment URL. Please try again.</p></div>';
        return;
    }

    // Stash the link id on the session token so verify_gateway_payment('adyen')
    // can check the real status when the buyer returns.
    if ($token && isset($_SESSION['payment_tokens'][$token])) {
        $_SESSION['payment_tokens'][$token]['adyen_link_id'] = $linkId;
    }
    ?>
    <div style="text-align:center;padding:20px;color:#64748b;">
        <p style="margin-bottom:12px;">Redirecting to Adyen secure checkout&hellip;</p>
        <div style="margin:0 auto;width:40px;height:40px;border:4px solid #e5e7eb;border-top-color:#0abf53;border-radius:50%;animation:adyen-spin 0.8s linear infinite;"></div>
    </div>
    <script>
        (function () {
            var url = <?= json_encode($linkUrl) ?>;
            var delay = <?= json_encode($cfg['dev_mode'] ? 900 : 400) ?>;
            setTimeout(function () { window.location.href = url; }, delay);
        })();
    </script>
    <style>@keyframes adyen-spin { to { transform: rotate(360deg); } }</style>
    <?php
    return;
}

// ============================================================================
// ERROR HANDLING
// ============================================================================
$apiMessage = $link['message'] ?? ($res['error'] ?: 'Unable to create Adyen payment link.');
error_log('ADYEN PAYMENTLINK ERROR | Invoice: ' . ($booking['invoice_id'] ?? '') . ' | HTTP ' . $res['http_code'] . ' | ' . $apiMessage);

echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px;border:1px solid #fecaca;">';
echo '<h4 style="color:#dc2626;margin-top:0;">Adyen Payment Error</h4>';
echo '<p style="color:#991b1b;">' . htmlspecialchars($apiMessage) . '</p>';
if ($cfg['dev_mode']) {
    echo '<details style="margin-top:12px;font-size:12px;color:#6b7280;"><summary style="cursor:pointer;">Debug</summary>';
    echo '<pre style="background:#f3f4f6;padding:10px;margin-top:8px;overflow:auto;">HTTP: ' . htmlspecialchars((string) $res['http_code']) . "\nAmount: " . htmlspecialchars((string) $value) . ' (' . htmlspecialchars($currency) . ")\nMerchant: " . htmlspecialchars($cfg['merchant_account']) . '</pre></details>';
}
echo '<p style="margin-top:15px;"><a href="' . htmlspecialchars(root . 'invoice/' . ($booking['invoice_id'] ?? '')) . '" style="color:#2563eb;">&larr; Return to Invoice</a></p>';
echo '</div>';

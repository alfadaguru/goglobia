<?php
@$SECURE or die('Access Denied!');

if (!function_exists('process_payment')) {
    require_once __DIR__ . '/../../lib/payment-gateway.php';
}

if (!defined('COINSBUY_GATEWAY_REGISTERED')) {
    define('COINSBUY_GATEWAY_REGISTERED', true);
    if (isset($router) && is_object($router)) {
        $router->post('payment/coinsbuy', function () {
            require __DIR__ . '/coinsbuy.php';
        });
    }
}

// Coinsbuy payment view aligned with the unified payment flow

if (!isset($_POST['payload']) && !isset($paymentData)) {
    return;
}

try {
    $context = build_coinsbuy_context($paymentData ?? null, $_POST);
    $tokenData = get_token_data($context['token']);

    if (!$tokenData) {
        throw new Exception('Payment session expired. Please open the invoice again and restart the payment.');
    }

    $paymentPage = $depositId = null;
    $attempts = [];

    while (true) {
        try {
            $envLabel = ($context['api']['dev_mode'] ? 'sandbox' : 'production') . ' (' . ($context['api']['mode'] ?? 'legacy') . ' API)';
            $attempts[] = $envLabel;
            
            $legacyMode = ($context['api']['mode'] ?? 'v3') === 'legacy';
            $authToken = $legacyMode ? null : coinsbuy_authenticate($context['api']);
            [$paymentPage, $depositId] = coinsbuy_create_deposit($context, $authToken);
            break;
        } catch (Exception $gatewayError) {
            if (coinsbuy_should_switch_environment($gatewayError) && coinsbuy_try_alternate_environment($context)) {
                continue;
            }

            $attemptsSummary = 'Tried: ' . implode(', ', $attempts);
            throw new Exception($gatewayError->getMessage() . ' (' . $attemptsSummary . ')');
        }
    }

    if (!headers_sent()) {
        header('Location: ' . $paymentPage);
        exit;
    }

    echo '<div style="text-align:center;padding:32px;">';
    echo '<p style="margin-bottom:8px;">Redirecting to Coinsbuy...</p>';
    echo '<p><a href="' . htmlspecialchars($paymentPage, ENT_QUOTES) . '">Continue to payment</a></p>';
    echo '</div>';

} catch (Exception $e) {
    echo '<div style="max-width:520px;margin:40px auto;padding:20px;border:1px solid #fecaca;border-radius:10px;background:#fff1f2;">';
    echo '<h4 style="margin-top:0;color:#b91c1c;">Coinsbuy Payment Error</h4>';
    echo '<p style="color:#7f1d1d;">' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p style="font-size:13px;color:#7f1d1d;">Please return to the invoice and choose another payment option.</p>';
    echo '</div>';
}

function build_coinsbuy_context($paymentData, $post)
{
    global $db;

    if (isset($post['payload'])) {
        $payload = json_decode(base64_decode($post['payload']));
        if (!$payload) {
            throw new Exception('Invalid payment payload.');
        }

        if (isset($payload->type) && $payload->type === 'wallet' && isset($post['price'])) {
            $payload->price = $post['price'];
        }

        $booking = [
            'invoice_id' => $payload->booking_ref_no ?? $payload->invoice_id,
            'price_markup' => $payload->price,
            'currency_markup' => $payload->currency,
            'email' => $payload->client_email,
            'ref' => $payload->booking_ref_no ?? $payload->invoice_id
        ];

        $token = $paymentData['token'] ?? ($post['payment_token'] ?? $post['payload']);
        $tokenParam = urlencode($token ?? '');
        $successUrl = $post['success_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . $tokenParam . '&payment_status=success');
        $cancelUrl = $post['cancel_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . $tokenParam . '&payment_status=cancel');
        $failureUrl = $post['failure_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . $tokenParam . '&payment_status=failure');

        if (isset($paymentData['gateway'])) {
            $gateway = $paymentData['gateway'];
        } else {
            $gateway = $db->get('payment_gateways', '*', ['name' => 'coinsbuy', 'status' => 1]);
        }
    } elseif (isset($paymentData)) {
        $booking = $paymentData['booking'];
        $gateway = $paymentData['gateway'];
        $token = $paymentData['token'];
        $successUrl = $paymentData['success_url'];
        $cancelUrl = $paymentData['cancel_url'];
        $failureUrl = $paymentData['failure_url'];
    } else {
        throw new Exception('Missing Coinsbuy payment data.');
    }

    if (!$gateway) {
        $gateway = get_base_gateway_record('coinsbuy');
    }

    if (!$booking || !$token || !$gateway) {
        throw new Exception('Incomplete Coinsbuy payment context.');
    }

    $gateway = hydrate_coinsbuy_gateway_record($gateway);

    $trackingId = !empty($booking['ref']) ? $booking['ref'] : $booking['invoice_id'];
    $amount = number_format((float)$booking['price_markup'], 2, '.', '');
    $currency = strtoupper($booking['currency_markup'] ?? 'USD');

    $gatewayArray = is_array($gateway) ? $gateway : (array)$gateway;
    $gatewayConfig = get_gateway_config($gatewayArray);

    $login = resolve_coinsbuy_value([
        $gatewayArray['c1'] ?? null,
        $gatewayConfig['api_key'] ?? null,
        $gatewayConfig['username'] ?? null,
        getenv('COINSBUY_CLIENT_ID') ?: getenv('COINSBUY_LOGIN') ?: null
    ]);

    $password = resolve_coinsbuy_value([
        $gatewayArray['c2'] ?? null,
        $gatewayConfig['secret_key'] ?? null,
        $gatewayConfig['password'] ?? null,
        getenv('COINSBUY_CLIENT_SECRET') ?: getenv('COINSBUY_PASSWORD') ?: null
    ]);

    $walletId = resolve_coinsbuy_value([
        $gatewayArray['c3'] ?? null,
        $gatewayConfig['additional_1'] ?? null,
        getenv('COINSBUY_WALLET') ?: null
    ]);

    $callbackSecret = resolve_coinsbuy_value([
        $gatewayArray['c4'] ?? null,
        $gatewayConfig['additional_2'] ?? null,
        getenv('COINSBUY_CALLBACK_SECRET') ?: null
    ]);

    $devMode = resolve_coinsbuy_value([
        $gatewayArray['dev_mode'] ?? null,
        $gatewayConfig['dev_mode'] ?? null,
        getenv('COINSBUY_DEV_MODE') ?: null
    ], 0);

    if (!$login || !$password) {
        throw new Exception('Coinsbuy client ID/secret are missing in the gateway configuration.');
    }

    $prodV3 = 'https://v3.api.coinsbuy.com';
    $sandboxV3 = 'https://v3.api-sandbox.coinsbuy.com';
    $prodLegacy = 'https://api.coinsbuy.com';
    $sandboxLegacy = 'https://api-sandbox.coinsbuy.com';

    $primaryV3 = $devMode ? $sandboxV3 : $prodV3;
    $primaryLegacy = $devMode ? $sandboxLegacy : $prodLegacy;

    $configuredVersion = strtolower(getenv('COINSBUY_API_VERSION') ?: ($gatewayArray['env'] ?? 'legacy'));
    $useV3 = $configuredVersion === 'v3' || $configuredVersion === 'new';
    $envKey = $devMode ? 'sandbox' : 'prod';

    return [
        'booking' => $booking,
        'token' => $token,
        'tracking_id' => $trackingId,
        'amount' => $amount,
        'currency' => $currency,
        'urls' => [
            'success' => $successUrl,
            'cancel' => $cancelUrl,
            'failure' => $failureUrl
        ],
        'api' => [
            'mode' => $useV3 ? 'v3' : 'legacy',
            'base' => rtrim($useV3 ? $primaryV3 : $primaryLegacy, '/'),
            'legacy_base' => rtrim($primaryLegacy, '/'),
            'prod_v3_base' => rtrim($prodV3, '/'),
            'sandbox_v3_base' => rtrim($sandboxV3, '/'),
            'prod_legacy_base' => rtrim($prodLegacy, '/'),
            'sandbox_legacy_base' => rtrim($sandboxLegacy, '/'),
            'login' => $login,
            'password' => $password,
            'wallet' => $walletId,
            'callback_secret' => $callbackSecret,
            'dev_mode' => (bool)$devMode,
            'attempted_envs' => [$envKey => true]
        ]
    ];
}

function coinsbuy_authenticate($api)
{
    $tokenUrl = rtrim($api['base'], '/') . '/oauth/token';
    $payload = [
        'grant_type' => 'client_credentials',
        'client_id' => $api['login'],
        'client_secret' => $api['password']
    ];

    $response = coinsbuy_request($tokenUrl, $payload, [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json'
    ], false);

    $token = $response['access_token']
        ?? ($response['data']['attributes']['access'] ?? null);

    if (!$token) {
        throw new Exception('Unable to authenticate with Coinsbuy.');
    }

    return $token;
}

function coinsbuy_create_deposit($context, $authToken)
{
    if (($context['api']['mode'] ?? 'v3') === 'legacy') {
        return coinsbuy_create_legacy_deposit($context);
    }

    $attributes = [
        'label' => 'Booking ' . $context['tracking_id'],
        'tracking_id' => $context['tracking_id'],
        'target_amount_requested' => (float)$context['amount'],
        'currency' => $context['currency'],
        'confirmations_needed' => 2,
        'customer_email' => $context['booking']['email'] ?? null,
        'metadata' => [
            'invoice_id' => $context['booking']['invoice_id'],
            'token' => $context['token']
        ],
        'redirect_urls' => [
            'success' => $context['urls']['success'],
            'cancel' => $context['urls']['cancel'],
            'failure' => $context['urls']['failure']
        ]
    ];

    $payload = ['data' => ['type' => 'deposits', 'attributes' => $attributes]];

    if (!empty($context['api']['wallet'])) {
        $payload['data']['relationships'] = [
            'wallet' => [
                'data' => [
                    'type' => 'wallet',
                    'id' => $context['api']['wallet']
                ]
            ]
        ];
    }

    $endpoint = rtrim($context['api']['base'], '/') . '/deposits/';

    try {
        $response = coinsbuy_request($endpoint, $payload, [
            'Authorization: Bearer ' . $authToken,
            'Content-Type: application/vnd.api+json',
            'Accept: application/vnd.api+json'
        ]);
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'HTTP 404') !== false) {
            return coinsbuy_create_legacy_deposit($context);
        } else {
            throw $e;
        }
    }

    $paymentPage = $response['data']['attributes']['payment_page']
        ?? $response['data']['attributes']['redirect_url']
        ?? null;
    $depositId = $response['data']['id'] ?? null;

    if (!$paymentPage) {
        throw new Exception('Coinsbuy did not return a payment page.');
    }

    return [$paymentPage, $depositId];
}

function coinsbuy_authenticate_legacy($api)
{
    $base = $api['legacy_base'] ?? $api['base'];
    $response = coinsbuy_request(rtrim($base, '/') . '/token/', [
        'data' => [
            'type' => 'auth-token',
            'attributes' => [
                'login' => $api['login'],
                'password' => $api['password']
            ]
        ]
    ]);

    $token = $response['data']['attributes']['access'] ?? null;
    if (!$token) {
        throw new Exception('Unable to authenticate with legacy Coinsbuy API.');
    }

    return $token;
}

function coinsbuy_create_legacy_deposit($context)
{
    $legacyToken = coinsbuy_authenticate_legacy($context['api']);
    $legacyBase = $context['api']['legacy_base'] ?? $context['api']['base'];
    $legacyEndpoint = rtrim($legacyBase, '/') . '/deposit/';
    $payload = build_legacy_coinsbuy_payload($context);

    $response = coinsbuy_request($legacyEndpoint, $payload, [
        'Authorization: Bearer ' . $legacyToken,
        'Content-Type: application/vnd.api+json',
        'Accept: application/vnd.api+json'
    ]);

    $paymentPage = $response['data']['attributes']['payment_page']
        ?? $response['data']['attributes']['payment_page_redirect_url']
        ?? null;
    $depositId = $response['data']['id'] ?? null;

    if (!$paymentPage) {
        throw new Exception('Coinsbuy legacy API did not return a payment page.');
    }

    return [$paymentPage, $depositId];
}

function build_legacy_coinsbuy_payload($context)
{
    $attributes = [
        'label' => 'Booking ' . $context['tracking_id'],
        'tracking_id' => $context['tracking_id'],
        'time_limit' => '900',
        'inaccuracy' => 5,
        'target_amount_requested' => $context['amount'],
        'callback_url' => root . 'coinsbuy/callback.php',
        'cayment_page_redirect_url' => $context['urls']['success'],
        'payment_page_button_text' => 'Return to Invoice'
    ];

    $payload = [
        'data' => [
            'type' => 'deposit',
            'attributes' => $attributes
        ]
    ];

    if (!empty($context['api']['wallet'])) {
        $payload['data']['relationships'] = [
            'wallet' => [
                'data' => [
                    'type' => 'wallet',
                    'id' => $context['api']['wallet']
                ]
            ]
        ];
    }

    return $payload;
}

function hydrate_coinsbuy_gateway_record($gateway)
{
    $record = is_array($gateway) ? $gateway : (array)$gateway;
    if (empty($record)) {
        $record = [];
    }

    $name = strtolower($record['name'] ?? $record['gateway_name'] ?? 'coinsbuy');

    if ((!isset($record['c1']) || !$record['c1']) || (!isset($record['c2']) || !$record['c2']) || (!isset($record['c3']) || !$record['c3'])) {
        $baseRecord = get_base_gateway_record($name);

        if ($baseRecord) {
            foreach (['c1', 'c2', 'c3', 'dev_mode'] as $key) {
                if (empty($record[$key]) && isset($baseRecord[$key])) {
                    $record[$key] = $baseRecord[$key];
                }
            }
        }
    }

    return $record;
}

function get_base_gateway_record($gatewayName)
{
    if (!function_exists('base')) {
        return null;
    }

    /** @var object $baseInstance */
    $baseInstance = call_user_func('base');
    $gateways = $baseInstance->payment_gateways ?? [];

    foreach ($gateways as $gateway) {
        $source = is_array($gateway) ? $gateway : (array)$gateway;
        $name = strtolower($source['name'] ?? '');
        if ($name === strtolower($gatewayName)) {
            return $source;
        }
    }

    return null;
}

function resolve_coinsbuy_value(array $options, $default = '')
{
    foreach ($options as $value) {
        if ($value === null) {
            continue;
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value !== '' && $value !== false) {
            return $value;
        }
    }

    return $default;
}

function coinsbuy_should_switch_environment(Exception $exception)
{
    $message = strtolower($exception->getMessage());

    return strpos($message, 'no active account') !== false
        || strpos($message, 'inactive account') !== false
        || strpos($message, 'account not found') !== false;
}

function coinsbuy_try_alternate_environment(&$context)
{
    if (empty($context['api']) || !is_array($context['api'])) {
        return false;
    }

    $api =& $context['api'];
    if (empty($api['attempted_envs']) || !is_array($api['attempted_envs'])) {
        $api['attempted_envs'] = [];
    }

    $currentDev = !empty($api['dev_mode']);
    $targetDev = !$currentDev;
    $attemptKey = $targetDev ? 'sandbox' : 'prod';

    if (!empty($api['attempted_envs'][$attemptKey])) {
        return false;
    }

    coinsbuy_apply_environment($context, $targetDev);
    $api['attempted_envs'][$attemptKey] = true;

    return true;
}

function coinsbuy_apply_environment(&$context, $useDev)
{
    if (empty($context['api']) || !is_array($context['api'])) {
        return;
    }

    $api =& $context['api'];
    $api['dev_mode'] = (bool)$useDev;

    $legacyKey = $useDev ? 'sandbox_legacy_base' : 'prod_legacy_base';
    $v3Key = $useDev ? 'sandbox_v3_base' : 'prod_v3_base';

    if (!empty($api[$legacyKey])) {
        $api['legacy_base'] = rtrim($api[$legacyKey], '/');
    }

    $mode = $api['mode'] ?? 'v3';
    if ($mode === 'v3' && !empty($api[$v3Key])) {
        $api['base'] = rtrim($api[$v3Key], '/');
    } elseif (!empty($api[$legacyKey])) {
        $api['base'] = rtrim($api[$legacyKey], '/');
    }
}

function coinsbuy_request($url, $payload = null, array $headers = [], $json = true)
{
    $ch = curl_init($url);

    $defaultHeaders = $json
        ? ['Content-Type: application/vnd.api+json', 'Accept: application/vnd.api+json']
        : ['Accept: application/json'];

    $headerBag = array_values(array_filter(array_merge($defaultHeaders, $headers)));

    $body = null;
    if ($payload !== null) {
        if (is_string($payload)) {
            $body = $payload;
        } elseif ($json) {
            $body = json_encode($payload);
        } else {
            $body = http_build_query($payload, '', '&');
        }
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => $headerBag,
    ];

    if ($payload !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $body;
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        throw new Exception('Coinsbuy request failed: ' . $error);
    }

    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $decoded = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Unable to parse Coinsbuy response: ' . json_last_error_msg());
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        $message = $decoded['errors'][0]['detail'] ?? $decoded['message'] ?? 'Unknown error';
        throw new Exception('Coinsbuy API error (HTTP ' . $statusCode . '): ' . $message);
    }

    return $decoded;
}

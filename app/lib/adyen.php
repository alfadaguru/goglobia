<?php
/**
 * Adyen helper library (Hosted Checkout / Pay by Link, raw cURL — no SDK).
 *
 * Credential mapping on the payment_gateways row:
 *   c1 = API Key          (Checkout API key from the Adyen Customer Area)
 *   c2 = Merchant Account  (e.g. "YourCompanyECOM")
 *   c3 = HMAC Key          (from the Standard webhook configuration)
 *   c4 = Live URL prefix   (live only — Developers > API URLs; blank for test)
 *   c5 = (unused)
 *   dev_mode: '1' = TEST endpoints, '0' = LIVE endpoints
 *
 * This file is Adyen-specific and does not alter the shared payment flow used by
 * other gateways. It is required on demand by the Adyen gateway view, the
 * verify_gateway_payment() 'adyen' case, and the Adyen webhook route.
 */

if (!defined('ADYEN_API_VERSION')) {
    define('ADYEN_API_VERSION', 'v71');
}

/**
 * Resolve Adyen configuration + base URL from a payment_gateways row.
 */
function adyen_config(array $gateway): array
{
    $devMode      = (string) ($gateway['dev_mode'] ?? '1') === '1';
    $apiKey       = trim((string) ($gateway['c1'] ?? ''));
    $merchant     = trim((string) ($gateway['c2'] ?? ''));
    $hmacKey      = trim((string) ($gateway['c3'] ?? ''));
    $livePrefix   = trim((string) ($gateway['c4'] ?? ''));

    if ($devMode) {
        $base = 'https://checkout-test.adyen.com/' . ADYEN_API_VERSION;
    } else {
        // Live requires the merchant-specific URL prefix.
        $base = 'https://' . $livePrefix . '-checkout-live.adyenpayments.com/checkout/' . ADYEN_API_VERSION;
    }

    return [
        'api_key'          => $apiKey,
        'merchant_account' => $merchant,
        'hmac_key'         => $hmacKey,
        'live_url_prefix'  => $livePrefix,
        'dev_mode'         => $devMode,
        'base_url'         => $base,
    ];
}

/**
 * Number of minor-unit decimals Adyen expects for a currency.
 * Most currencies use 2; a few use 0 or 3.
 */
function adyen_currency_exponent(string $currency): int
{
    $currency = strtoupper(trim($currency));
    $zero  = ['JPY','KRW','VND','CLP','ISK','PYG','UGX','RWF','XOF','XAF','XPF','BIF','DJF','GNF','KMF','MGA','VUV'];
    $three = ['KWD','BHD','OMR','JOD','TND','LYD','IQD'];
    if (in_array($currency, $zero, true))  return 0;
    if (in_array($currency, $three, true)) return 3;
    return 2;
}

/**
 * Convert a major-unit decimal amount to Adyen minor units (integer).
 */
function adyen_to_minor_units($amount, string $currency): int
{
    $exp = adyen_currency_exponent($currency);
    return (int) round(((float) $amount) * (10 ** $exp));
}

/**
 * Perform a raw Adyen Checkout API request. Returns ['http_code'=>int, 'json'=>array|null, 'raw'=>string, 'error'=>string].
 */
function adyen_api_request(array $cfg, string $method, string $path, ?array $body = null): array
{
    $url = $cfg['base_url'] . '/' . ltrim($path, '/');
    $ch  = curl_init($url);

    $headers = [
        'x-API-key: ' . $cfg['api_key'],
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);

    $raw   = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    curl_close($ch);

    $json = null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $json = $decoded;
        }
    }

    return ['http_code' => $code, 'json' => $json, 'raw' => (string) $raw, 'error' => $err];
}

/**
 * Escape a value for the Adyen HMAC data-to-sign string ('\' and ':' are escaped).
 */
function adyen_hmac_escape($value): string
{
    $value = (string) $value;
    return str_replace(['\\', ':'], ['\\\\', '\\:'], $value);
}

/**
 * Verify the HMAC signature of a single Adyen notification item.
 * Follows Adyen's standard HMAC-SHA256 algorithm.
 */
function adyen_verify_hmac(array $notificationItem, string $hmacKeyHex): bool
{
    $hmacKeyHex = trim($hmacKeyHex);
    if ($hmacKeyHex === '') {
        return false;
    }

    $provided = (string) ($notificationItem['additionalData']['hmacSignature'] ?? '');
    if ($provided === '') {
        return false;
    }

    $fields = [
        $notificationItem['pspReference']            ?? '',
        $notificationItem['originalReference']       ?? '',
        $notificationItem['merchantAccountCode']     ?? '',
        $notificationItem['merchantReference']       ?? '',
        (string) ($notificationItem['amount']['value']    ?? ''),
        (string) ($notificationItem['amount']['currency'] ?? ''),
        $notificationItem['eventCode']               ?? '',
        $notificationItem['success']                 ?? '',
    ];

    $escaped     = array_map('adyen_hmac_escape', $fields);
    $dataToSign  = implode(':', $escaped);

    $binKey    = @hex2bin($hmacKeyHex);
    if ($binKey === false) {
        return false;
    }

    $computed = base64_encode(hash_hmac('sha256', $dataToSign, $binKey, true));

    return hash_equals($computed, $provided);
}

/**
 * Test Adyen credentials without charging anyone. Calls POST /paymentMethods
 * (a non-mutating auth + merchant-account check) and returns a structured,
 * terminal-friendly result. Used by the admin "Test Credentials" button.
 *
 * @return array ['success'=>bool, 'message'=>string, 'steps'=>string[], 'http_code'=>int]
 */
function adyen_test_credentials(array $cfg, string $currency = 'USD'): array
{
    $currency = strtoupper(trim($currency)) ?: 'USD';
    $steps = [];
    $steps[] = '[START] Adyen credential validation';
    $steps[] = '[ENV] Environment: ' . ($cfg['dev_mode'] ? 'TEST' : 'LIVE');
    $steps[] = '[URL] ' . $cfg['base_url'];

    if ($cfg['api_key'] === '') {
        $steps[] = '[ERROR] API Key (c1) is empty';
        return ['success' => false, 'message' => 'API key is missing', 'steps' => $steps, 'http_code' => 0];
    }
    if ($cfg['merchant_account'] === '') {
        $steps[] = '[ERROR] Merchant Account (c2) is empty';
        return ['success' => false, 'message' => 'Merchant account is missing', 'steps' => $steps, 'http_code' => 0];
    }
    if (!$cfg['dev_mode'] && $cfg['live_url_prefix'] === '') {
        $steps[] = '[ERROR] Live URL Prefix (c4) is required for LIVE mode';
        return ['success' => false, 'message' => 'Live URL prefix missing', 'steps' => $steps, 'http_code' => 0];
    }

    $steps[] = '[OK] API Key present (' . substr($cfg['api_key'], 0, 6) . '…' . substr($cfg['api_key'], -4) . ', length ' . strlen($cfg['api_key']) . ')';
    $steps[] = '[OK] Merchant Account: ' . $cfg['merchant_account'];
    $steps[] = '[CALL] POST /paymentMethods (non-charging authentication check)…';

    $body = [
        'merchantAccount' => $cfg['merchant_account'],
        'amount'          => ['currency' => $currency, 'value' => 1000],
        'countryCode'     => 'US',
        'channel'         => 'Web',
    ];
    $res = adyen_api_request($cfg, 'POST', 'paymentMethods', $body);
    $steps[] = '[HTTP] ' . $res['http_code'];
    $j = is_array($res['json']) ? $res['json'] : [];

    if ($res['http_code'] === 200) {
        $n = (isset($j['paymentMethods']) && is_array($j['paymentMethods'])) ? count($j['paymentMethods']) : 0;
        $steps[] = '[OK] Authenticated — ' . $n . ' payment method(s) available for ' . $currency;
        $steps[] = '[SUCCESS] Credentials are valid and ready to accept payments';
        return ['success' => true, 'message' => 'Credentials valid — ' . $n . ' payment methods', 'steps' => $steps, 'http_code' => 200];
    }

    $msg = $j['message'] ?? ($res['error'] ?: 'Unknown error');
    $hint = '';
    if ($res['http_code'] === 401 || $res['http_code'] === 403) {
        // Adyen returns 403 "not allowed" for both bad keys and missing roles.
        $steps[] = '[ERROR] ' . $res['http_code'] . ' — ' . $msg;
        $steps[] = '[HINT] The API key is invalid, was not Saved in Adyen after generating, or the';
        $steps[] = '[HINT] credential is missing the Checkout role. Re-generate the key, click Save';
        $steps[] = '[HINT] changes in Adyen, and confirm the credential has the Checkout API role.';
    } elseif ($res['http_code'] === 422) {
        $steps[] = '[ERROR] 422 — ' . $msg;
        $steps[] = '[HINT] Check the Merchant Account code (c2) and the currency.';
    } else {
        $steps[] = '[ERROR] ' . $res['http_code'] . ' — ' . $msg;
    }
    return ['success' => false, 'message' => $msg, 'steps' => $steps, 'http_code' => $res['http_code']];
}

/**
 * Session-independent, idempotent finalize used by the Adyen WEBHOOK (which has
 * no PHP session and therefore cannot use the token-based handle_payment_callback).
 *
 * Marks the booking paid, records the transaction, and — when auto-issue is
 * enabled and the module/gateway dev modes match — triggers the module's issue
 * endpoint exactly like the core success path. Safe to call more than once.
 *
 * @return array ['success'=>bool, 'message'=>string, 'already'=>bool]
 */
function adyen_finalize_payment($db, string $invoiceId, ?string $pspReference, array $rawEvent = []): array
{
    $invoiceId = trim($invoiceId);
    if ($invoiceId === '') {
        return ['success' => false, 'message' => 'Missing invoice reference', 'already' => false];
    }

    $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
    if (!$booking) {
        return ['success' => false, 'message' => "Booking not found for invoice {$invoiceId}", 'already' => false];
    }

    // Idempotency: another callback (redirect return or an earlier webhook) already finalized.
    if (($booking['payment_status'] ?? '') === 'paid') {
        return ['success' => true, 'message' => 'Already paid', 'already' => true];
    }

    $module     = strtolower((string) ($booking['module'] ?? ''));
    $moduleType = (string) ($booking['module_type'] ?? '');
    $gatewayId  = $booking['payment_gateway'] ?? null;

    // Build a session-free token-like array for record_transaction().
    $tokenData = [
        'invoice_id'   => $invoiceId,
        'booking_id'   => $booking['id'] ?? null,
        'amount'       => $booking['price_markup'] ?? 0,
        'currency'     => $booking['currency_markup'] ?? 'USD',
        'gateway_id'   => $gatewayId,
        'gateway_name' => 'Adyen',
        'client_email' => $booking['email'] ?? '',
        'user_id'      => $booking['user_id'] ?? null,
        'module'       => $module,
        'module_type'  => $moduleType,
    ];

    // dev_mode consistency: collect the money either way, but only auto-issue when modes match.
    $devModeMismatch = false;
    if ($module && $gatewayId) {
        $moduleRecord  = $db->get('modules', ['dev_mode', 'name'], ['name' => $module]);
        $gatewayRecord = $db->get('payment_gateways', ['dev_mode', 'name'], ['id' => $gatewayId]);
        if ($moduleRecord && $gatewayRecord && (int) $moduleRecord['dev_mode'] !== (int) $gatewayRecord['dev_mode']) {
            $devModeMismatch = true;
            error_log("ADYEN WEBHOOK DEV_MODE MISMATCH | Invoice: {$invoiceId} | module {$module} vs gateway {$gatewayRecord['name']}");
        }
    }

    // Mark paid.
    $db->update('bookings', [
        'payment_status' => 'paid',
        'booking_status' => 'confirmed',
        'transaction_id' => $pspReference,
        'paid_at'        => date('Y-m-d H:i:s'),
    ], ['invoice_id' => $invoiceId]);

    if (function_exists('record_transaction')) {
        record_transaction($tokenData, $pspReference, 'success', $rawEvent);
    }

    // Auto-issue (mirrors the core handle_payment_callback success path, condensed).
    $settingsRecord   = $db->get('settings', '*', ['id' => 1]);
    $autoIssueEnabled = $settingsRecord && (int) ($settingsRecord['booking_payment_issue'] ?? 0) === 1;

    $issuable = $autoIssueEnabled
        && !$devModeMismatch
        && $module
        && !in_array($module, ['flight', 'visas'], true)
        && !in_array(strtolower($moduleType), ['tours', 'tour'], true);

    if ($issuable) {
        try {
            $apiUrl = root . 'modules/' . $moduleType . '/' . $module . '/issue';
            $ch = curl_init($apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query(['invoice_id' => $invoiceId]),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_TIMEOUT        => 300,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $resp = trim((string) $resp);
            $resp = preg_replace('/^\xEF\xBB\xBF/', '', $resp);
            $parsed = json_decode($resp, true);

            $ok = is_array($parsed) && (
                (!empty($parsed['status']) && $parsed['status'] === true) ||
                (!empty($parsed['success']) && $parsed['success'] === true)
            );

            if ($ok) {
                $pnr = $parsed['Prn'] ?? $parsed['pnr'] ?? $parsed['confirmation_number']
                    ?? $parsed['booking_reference'] ?? $parsed['reference'] ?? null;
                $upd = ['booking_status' => $parsed['booking_status'] ?? 'confirmed', 'error_response' => null, 'booking_payment_issue' => null];
                if (!empty($pnr)) {
                    $upd['pnr'] = $pnr;
                }
                if ($moduleType !== 'rail') {
                    $upd['booking_response'] = json_encode($parsed);
                }
                $db->update('bookings', $upd, ['invoice_id' => $invoiceId]);

                if (function_exists('triggerNotification')) {
                    triggerNotification('booking.issued', [
                        'invoice_id'     => $invoiceId,
                        'pnr'            => $pnr,
                        'module'         => $module,
                        'booking_id'     => $booking['id'] ?? null,
                        'user_id'        => $booking['user_id'] ?? null,
                        'customer_email' => $booking['email'] ?? '',
                        'amount'         => $tokenData['amount'],
                        'currency'       => $tokenData['currency'],
                    ]);
                }
            } else {
                $err = is_array($parsed)
                    ? ($parsed['response_error'] ?? $parsed['error'] ?? $parsed['message'] ?? $resp)
                    : ('HTTP ' . $code);
                $db->update('bookings', [
                    'error_response'         => is_string($err) ? $err : json_encode($err),
                    'booking_status'         => 'pending',
                    'booking_payment_issue'  => is_string($err) ? $err : json_encode($err),
                ], ['invoice_id' => $invoiceId]);
                error_log("ADYEN WEBHOOK ISSUE FAILED | Invoice: {$invoiceId} | {$apiUrl} | " . (is_string($err) ? $err : json_encode($err)));
            }
        } catch (\Throwable $e) {
            error_log("ADYEN WEBHOOK ISSUE EXCEPTION | Invoice: {$invoiceId} | " . $e->getMessage());
        }
    }

    return ['success' => true, 'message' => 'Payment finalized', 'already' => false];
}

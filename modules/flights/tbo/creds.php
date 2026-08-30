<?php
// path: modules/flights/tbo/creds.php
// TBO Air (tboair.com) credential helpers + validation route
// POST flights/tbo/creds

global $router;

// ===========================================================================
// HELPERS (shared by search.php / revalidate.php / actions/*)
// ===========================================================================

function getTboModule($db)
{
    return $db->get('modules', '*', [
        'name' => 'tbo',
        'type' => 'flights',
    ]);
}

function getTboCredentials($db)
{
    $module = getTboModule($db);
    if (!$module) return null;

    $creds = [];
    if (!empty($module['credentials'])) {
        $parsed = json_decode($module['credentials'], true);
        if (is_array($parsed)) $creds = $parsed;
    }
    foreach (['c1', 'c2'] as $col) {
        if (empty($creds[$col]) && !empty($module[$col])) {
            $creds[$col] = $module[$col];
        }
    }

    $devMode = (isset($module['dev_mode']) && $module['dev_mode'] == '1');

    return [
        'username'    => $creds['c1'] ?? '',   // TBO UserName
        'password'    => $creds['c2'] ?? '',   // TBO Password
        'environment' => $devMode ? 'dev' : 'production',
        'debug'       => $devMode,
        'module'      => $module,
    ];
}

/**
 * TBO Air base URLs. Search + Detail live on the search host, Booking on the
 * booking host. Dev (xmlout*) and production hosts per TBO documentation.
 */
function getTboBaseUrls($credentials)
{
    if (strtolower($credentials['environment'] ?? 'dev') === 'dev') {
        return [
            'search'  => 'https://xmloutapi.tboair.com',
            'booking' => 'https://xmloutbookingapi.tboair.com',
        ];
    }
    return [
        'search'  => 'https://searchapi.tboair.com',
        'booking' => 'https://bookingapi.tboair.com',
    ];
}

/** Best-effort end-user IP for TBO's IPAddress field. */
function tboClientIp()
{
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? (isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]) : null)
        ?? $_SERVER['REMOTE_ADDR']
        ?? '127.0.0.1';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
}

function tboBrowserAgent()
{
    return $_SERVER['HTTP_USER_AGENT']
        ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
}

/** Plain JSON POST helper used for every TBO call. */
function tboApiPost($url, array $payload, $timeout = null, $connectTimeout = null)
{
    $requestTimeout = $timeout !== null
        ? (int)$timeout
        : (defined('SUPPLIER_REQUEST_TIMEOUT') ? (int)SUPPLIER_REQUEST_TIMEOUT : 60);
    $connTimeout = $connectTimeout !== null
        ? (int)$connectTimeout
        : (defined('SUPPLIER_CONNECT_TIMEOUT') ? (int)SUPPLIER_CONNECT_TIMEOUT : 10);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => $requestTimeout,
        CURLOPT_CONNECTTIMEOUT => $connTimeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);

    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    return [
        'success'    => ($httpCode >= 200 && $httpCode < 300 && !$curlErr),
        'http_code'  => $httpCode,
        'raw'        => $raw,
        'data'       => json_decode((string)$raw, true),
        'curl_error' => $curlErr ?: null,
    ];
}

/**
 * Authenticate against TBO (ValidateAgency) and return
 * ['TokenId' => ..., 'TrackingId' => ...] or null on failure.
 */
function tboAuthenticate($credentials, &$rawResponse = null)
{
    $urls = getTboBaseUrls($credentials);

    $result = tboApiPost($urls['search'] . '/API/V1/Authenticate/ValidateAgency', [
        'UserName'    => $credentials['username'],
        'Password'    => $credentials['password'],
        'BookingMode' => 'API',
        'IPAddress'   => tboClientIp(),
    ], 30);

    $rawResponse = $result['raw'];
    $data = $result['data'];

    if (!empty($data['TokenId'])) {
        return [
            'TokenId'    => $data['TokenId'],
            'TrackingId' => $data['TrackingId'] ?? '',
        ];
    }
    return null;
}

/**
 * Get a TBO token, reusing a same-day cached one from the module credentials
 * JSON unless $forceNew. A ResultId is only valid with the TokenId that
 * produced it, so booking-time calls must use the token stored in
 * booking_data — never a fresh one.
 */
function tboGetToken($db, $credentials = null, $forceNew = false, &$authError = null)
{
    if ($credentials === null) $credentials = getTboCredentials($db);
    if (!$credentials || empty($credentials['username']) || empty($credentials['password'])) return null;

    $module = $credentials['module'] ?? getTboModule($db);
    $cacheKey = 'tbo_token_' . strtolower($credentials['environment']);

    if (!$forceNew && $module && !empty($module['credentials'])) {
        $stored = json_decode($module['credentials'], true) ?: [];
        $cached = $stored[$cacheKey] ?? null;
        if (is_array($cached)
            && !empty($cached['TokenId'])
            && ($cached['date'] ?? '') === date('Y-m-d')) {
            return ['TokenId' => $cached['TokenId'], 'TrackingId' => $cached['TrackingId'] ?? ''];
        }
    }

    $rawResponse = null;
    $token = tboAuthenticate($credentials, $rawResponse);
    if (!$token) {
        $parsed = json_decode((string)$rawResponse, true);
        $authError = is_array($parsed)
            ? ($parsed['Errors'][0]['UserMessage'] ?? $parsed['Error']['ErrorMessage'] ?? $parsed['Message'] ?? null)
            : null;
        if (!$authError && $rawResponse === null) {
            $authError = 'TBO authentication endpoint is unreachable.';
        }
    }
    if ($token && $module) {
        $stored = !empty($module['credentials']) ? (json_decode($module['credentials'], true) ?: []) : [];
        $stored[$cacheKey] = [
            'TokenId'    => $token['TokenId'],
            'TrackingId' => $token['TrackingId'],
            'date'       => date('Y-m-d'),
        ];
        $db->update('modules', ['credentials' => json_encode($stored)], ['id' => $module['id']]);
    }

    return $token;
}

/** True when a TBO response looks like an auth/token failure. */
function tboIsAuthError($data)
{
    $message = '';
    if (is_array($data)) {
        $message = strtolower((string)(
            $data['Error']['ErrorMessage']
            ?? $data['Errors'][0]['UserMessage']
            ?? $data['Message']
            ?? ''
        ));
    }
    return $message !== '' && (
        strpos($message, 'token') !== false
        || strpos($message, 'authenticat') !== false
        || strpos($message, 'session') !== false
        || strpos($message, 'credential') !== false
    );
}

/** Log a TBO API call without leaking credentials. */
function tboLog($db, $endpoint, $request, $response, $httpCode, $error = null, $invoiceId = null)
{
    $safeReq = is_array($request) ? $request : (json_decode((string)$request, true) ?? []);
    foreach (['Password', 'TokenId', 'TrackingId'] as $sensitive) {
        if (isset($safeReq[$sensitive])) $safeReq[$sensitive] = '***';
    }

    error_log(sprintf(
        "[TBO][%s] endpoint=%s invoice=%s http=%d error=%s\nREQ: %s\nRES: %s",
        date('Y-m-d H:i:s'),
        $endpoint,
        $invoiceId ?? '-',
        (int)$httpCode,
        $error ?? '-',
        json_encode($safeReq),
        is_string($response) ? substr($response, 0, 2000) : json_encode($response)
    ));
}

// ===========================================================================
// ROUTE: POST flights/tbo/creds — validates credentials via ValidateAgency
// ===========================================================================
$router->post('flights/tbo/creds', function () use ($db) {

    $start = microtime(true);

    $response = [
        'success'  => false,
        'message'  => '',
        'data'     => null,
        'metadata' => [
            'module'        => 'tbo',
            'service'       => 'flights',
            'provider'      => 'TBO Air API',
            'api_version'   => 'v1',
            'timestamp'     => date('c'),
            'response_time' => 0,
            'environment'   => 'production',
        ],
        'debug' => [
            'endpoint_used'    => '',
            'request_method'   => 'POST',
            'validation_steps' => [],
            'api_response'     => null,
        ],
    ];

    try {
        $username = trim($_POST['c1'] ?? '');
        $password = trim($_POST['c2'] ?? '');
        $devMode  = trim($_POST['dev_mode'] ?? $_POST['hidden_dev_mode'] ?? $_POST['env'] ?? '1');
        $isDev    = !in_array(strtolower($devMode), ['0', 'production', 'live'], true);

        $response['metadata']['environment'] = $isDev ? 'dev' : 'production';
        $response['debug']['validation_steps'][] = '[START] TBO Air credential validation';
        $response['debug']['validation_steps'][] = '[ENV] ' . ($isDev ? 'DEVELOPMENT' : 'PRODUCTION');

        if (empty($username) || empty($password)) {
            $missing = [];
            if (empty($username)) $missing[] = 'Username (c1)';
            if (empty($password)) $missing[] = 'Password (c2)';
            $response['message'] = 'Missing required credentials: ' . implode(', ', $missing);
            $response['debug']['validation_steps'][] = '[ERROR] ' . $response['message'];
            http_response_code(400);
            echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $response['debug']['validation_steps'][] = '[OK] Required credentials provided';

        $credentials = [
            'username'    => $username,
            'password'    => $password,
            'environment' => $isDev ? 'dev' : 'production',
        ];
        $urls = getTboBaseUrls($credentials);
        $response['debug']['endpoint_used'] = $urls['search'] . '/API/V1/Authenticate/ValidateAgency';
        $response['debug']['validation_steps'][] = '[API] ' . $response['debug']['endpoint_used'];

        $rawResponse = null;
        $token = tboAuthenticate($credentials, $rawResponse);

        $parsedRaw = json_decode((string)$rawResponse, true) ?: [];
        // Never expose the live token in the validation response
        if (isset($parsedRaw['TokenId'])) {
            $parsedRaw['TokenId'] = substr((string)$parsedRaw['TokenId'], 0, 8) . '...';
        }
        $response['debug']['api_response'] = $parsedRaw;

        if ($token) {
            $response['success'] = true;
            $response['message'] = 'TBO credentials validated. Agency authenticated successfully.';
            $response['data'] = [
                'connection_status' => 'connected',
                'authentication'    => [
                    'status'       => 'authenticated',
                    'token_prefix' => substr($token['TokenId'], 0, 8) . '...',
                    'tracking_id'  => $token['TrackingId'],
                ],
                'api_details' => [
                    'provider'    => 'TBO Air API',
                    'environment' => $isDev ? 'dev' : 'production',
                    'search_url'  => $urls['search'],
                    'booking_url' => $urls['booking'],
                ],
            ];
            $response['debug']['validation_steps'][] = '[SUCCESS] ValidateAgency returned a TokenId';
        } else {
            $apiMessage = $parsedRaw['Error']['ErrorMessage']
                ?? $parsedRaw['Errors'][0]['UserMessage']
                ?? $parsedRaw['Message']
                ?? 'Authentication failed. Please verify your TBO username and password.';
            $response['message'] = $apiMessage;
            $response['data'] = [
                'error_type'      => 'authentication_error',
                'raw_response'    => $parsedRaw,
                'troubleshooting' => [
                    'Verify your TBO Username and Password',
                    'Check the environment (dev vs production) matches your credentials',
                    'Confirm your server IP is whitelisted with TBO',
                    'Contact TBO support if the problem persists',
                ],
            ];
            $response['debug']['validation_steps'][] = '[FAILED] ValidateAgency did not return a TokenId';
        }

    } catch (Throwable $e) {
        $response['message'] = 'An error occurred during credential validation';
        $response['debug']['validation_steps'][] = 'Exception: ' . $e->getMessage();
    }

    $response['metadata']['response_time'] = round((microtime(true) - $start) * 1000) . 'ms';
    header('Content-Type: application/json');
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
});

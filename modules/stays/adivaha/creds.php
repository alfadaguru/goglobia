<?php
// path: modules/stays/adivaha/creds.php
// Adivaha B2C Hotels — credential helpers + validation route.
// POST stays/adivaha/creds
//
// The Hotel API shares the SAME auth + base URL as the Adivaha Flight API
// (backoffice.adivaha.com/b2c/api/v1, Bearer via POST /token, raw sk_ key also
// accepted). But the HOTEL supplier is a SEPARATE `modules` row (name='adivaha',
// type='stays') so it has its own credentials/markup/toggle. These helpers are
// namespaced with an "H" (adivahaH*) so they never collide with the flight
// module's adivaha* helpers, and they read the STAYS module row.
//
// Credential column mapping (admin Settings > Modules > Adivaha [stays]):
//   c1 = API secret key (sk_live_... / sk_test_...)
//   c2 = Partner ID (PID) — optional; enables the POST /token mint path
//   c3 = Base URL override — optional; defaults to the Live B2C API base
//   c4 = (reserved) token cache — written by adivahaHMintToken, never by admin
//   dev_mode: '1' = TEST (expects sk_test_), '0' = LIVE (expects sk_live_)

global $router;

if (!defined('ADIVAHA_HOTEL_DEFAULT_BASE_URL')) {
    define('ADIVAHA_HOTEL_DEFAULT_BASE_URL', 'https://backoffice.adivaha.com/b2c/api/v1');
}

if (!function_exists('getAdivahaHotelModule')) {
    function getAdivahaHotelModule($db)
    {
        return $db->get('modules', '*', ['name' => 'adivaha', 'type' => 'stays']);
    }
}

if (!function_exists('getAdivahaHotelCredentials')) {
    /**
     * Load + normalise the Adivaha HOTEL credentials from the stays module row.
     * Reads the discrete c1..c3 columns (c1=key, c2=partner id, c3=base url); a
     * legacy JSON `credentials` blob holding the same keys is also tolerated. The
     * minted 24h token is cached in `c4` (NOT `credentials`, which is enum('1','0')
     * and would silently reject a JSON write under strict mode).
     *
     * @return array|null {api_key, partner_id, base_url, mode, debug, module} or null.
     */
    function getAdivahaHotelCredentials($db)
    {
        $module = getAdivahaHotelModule($db);
        if (!$module) return null;

        $creds = [];
        if (!empty($module['credentials']) && $module['credentials'] !== '1' && $module['credentials'] !== '0') {
            $parsed = json_decode($module['credentials'], true);
            if (is_array($parsed)) $creds = $parsed;
        }
        foreach (['c1', 'c2', 'c3'] as $col) {
            if (empty($creds[$col]) && !empty($module[$col])) {
                $creds[$col] = $module[$col];
            }
        }

        $devMode = (isset($module['dev_mode']) && (string) $module['dev_mode'] === '1');

        $baseUrl = trim((string) ($creds['c3'] ?? ''));
        if ($baseUrl === '') $baseUrl = ADIVAHA_HOTEL_DEFAULT_BASE_URL;
        $baseUrl = rtrim($baseUrl, '/');

        return [
            'api_key'    => trim((string) ($creds['c1'] ?? '')),
            'partner_id' => trim((string) ($creds['c2'] ?? '')),
            'base_url'   => $baseUrl,
            'mode'       => $devMode ? 'test' : 'live',
            'debug'      => $devMode,
            'module'     => $module,
        ];
    }
}

if (!function_exists('adivahaHApiRequest')) {
    /**
     * Low-level JSON request to the Adivaha API (hotels). Adds the Bearer token,
     * JSON headers, optional extra headers (e.g. Idempotency-Key), honours the
     * shared supplier timeout constants, returns a normalised result array.
     */
    function adivahaHApiRequest($method, $url, $payload, $bearer, array $extraHeaders = [], $timeout = null, $connectTimeout = null)
    {
        $requestTimeout = $timeout !== null
            ? (int) $timeout
            : (defined('SUPPLIER_REQUEST_TIMEOUT') ? (int) SUPPLIER_REQUEST_TIMEOUT : 60);
        $connTimeout = $connectTimeout !== null
            ? (int) $connectTimeout
            : (defined('SUPPLIER_CONNECT_TIMEOUT') ? (int) SUPPLIER_CONNECT_TIMEOUT : 10);

        $headers = ['Accept: application/json'];
        if ($bearer !== '') $headers[] = 'Authorization: Bearer ' . $bearer;
        if ($payload !== null) $headers[] = 'Content-Type: application/json';
        foreach ($extraHeaders as $h) {
            if (is_string($h) && $h !== '') $headers[] = $h;
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $requestTimeout,
            CURLOPT_CONNECTTIMEOUT => $connTimeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if (strtoupper($method) === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $payload !== null ? json_encode($payload) : '';
        } elseif (strtoupper($method) !== 'GET') {
            $opts[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
            if ($payload !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
        }
        curl_setopt_array($ch, $opts);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        $data = json_decode((string) $raw, true);

        return [
            'success'    => ($httpCode >= 200 && $httpCode < 300 && !$curlErr),
            'http_code'  => (int) $httpCode,
            'raw'        => $raw,
            'data'       => is_array($data) ? $data : null,
            'curl_error' => $curlErr ?: null,
        ];
    }
}

if (!function_exists('adivahaHExtractError')) {
    /** Pull a human-readable error from an Adivaha error body / transport fault. */
    function adivahaHExtractError($result)
    {
        if (!empty($result['curl_error'])) return 'Network error: ' . $result['curl_error'];
        $d = $result['data'] ?? null;
        if (is_array($d)) {
            if (!empty($d['message'])) return (string) $d['message'];
            if (!empty($d['error']))   return (string) $d['error'];
        }
        if (!empty($result['raw']) && is_string($result['raw'])) {
            return 'HTTP ' . ($result['http_code'] ?? 0) . ': ' . substr($result['raw'], 0, 300);
        }
        return 'HTTP ' . ($result['http_code'] ?? 0) . ' (empty response)';
    }
}

if (!function_exists('adivahaHMintToken')) {
    /** Exchange X-Api-Key + X-Partner-Id for a 24h token via POST /token; cache in c4. */
    function adivahaHMintToken($db, $credentials = null, $forceNew = false, &$error = null)
    {
        if ($credentials === null) $credentials = getAdivahaHotelCredentials($db);
        if (!$credentials || $credentials['api_key'] === '' || $credentials['partner_id'] === '') {
            $error = 'Partner ID not configured — using the raw key as Bearer instead.';
            return null;
        }

        $module   = $credentials['module'] ?? getAdivahaHotelModule($db);
        $cacheKey = 'adivaha_hotel_token_' . $credentials['mode'];

        if (!$forceNew && $module && !empty($module['c4'])) {
            $stored = json_decode((string) $module['c4'], true) ?: [];
            $cached = is_array($stored) ? ($stored[$cacheKey] ?? null) : null;
            if (is_array($cached)
                && !empty($cached['token'])
                && !empty($cached['expires_at'])
                && strtotime($cached['expires_at']) > (time() + 300)) {
                return (string) $cached['token'];
            }
        }

        $url = $credentials['base_url'] . '/token';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_TIMEOUT        => (defined('SUPPLIER_REQUEST_TIMEOUT') ? (int) SUPPLIER_REQUEST_TIMEOUT : 30),
            CURLOPT_CONNECTTIMEOUT => (defined('SUPPLIER_CONNECT_TIMEOUT') ? (int) SUPPLIER_CONNECT_TIMEOUT : 10),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'X-Api-Key: ' . $credentials['api_key'],
                'X-Partner-Id: ' . $credentials['partner_id'],
            ],
        ]);
        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        $data = json_decode((string) $raw, true);

        if ($httpCode < 200 || $httpCode >= 300 || $curlErr || empty($data['token'])) {
            $error = $curlErr
                ? ('Network error: ' . $curlErr)
                : (is_array($data) && !empty($data['message']) ? (string) $data['message'] : ('Token mint failed (HTTP ' . $httpCode . ')'));
            return null;
        }

        $token     = (string) $data['token'];
        $expiresAt = !empty($data['expires_at'])
            ? (string) $data['expires_at']
            : date('c', time() + (int) ($data['expires_in'] ?? 86400));

        if ($module) {
            $stored = !empty($module['c4']) ? (json_decode((string) $module['c4'], true) ?: []) : [];
            if (!is_array($stored)) $stored = [];
            $stored[$cacheKey] = ['token' => $token, 'expires_at' => $expiresAt, 'minted_at' => date('c')];
            try {
                $db->update('modules', ['c4' => json_encode($stored)], ['id' => $module['id']]);
            } catch (Throwable $e) {
                error_log('adivahaHMintToken: could not cache token: ' . $e->getMessage());
            }
        }

        return $token;
    }
}

if (!function_exists('adivahaHBearer')) {
    /** Resolve the Bearer: a 24h token when a Partner ID is set, else the raw key. */
    function adivahaHBearer($db, $credentials = null, $forceNew = false)
    {
        if ($credentials === null) $credentials = getAdivahaHotelCredentials($db);
        if (!$credentials || $credentials['api_key'] === '') return '';

        if ($credentials['partner_id'] !== '') {
            $err = null;
            $token = adivahaHMintToken($db, $credentials, $forceNew, $err);
            if ($token) return $token;
            error_log('adivahaHBearer: token mint failed (' . ($err ?? '?') . ') — falling back to raw key.');
        }
        return $credentials['api_key'];
    }
}

if (!function_exists('adivahaHLog')) {
    /** Log a hotel API call without leaking the key/token. */
    function adivahaHLog($endpoint, $request, $response, $httpCode, $error = null, $invoiceId = null)
    {
        error_log(sprintf(
            "[ADIVAHA-HOTEL][%s] endpoint=%s invoice=%s http=%d error=%s\nREQ: %s\nRES: %s",
            date('Y-m-d H:i:s'),
            $endpoint,
            $invoiceId ?? '-',
            (int) $httpCode,
            $error ?? '-',
            is_array($request) ? json_encode($request) : (string) $request,
            is_string($response) ? substr($response, 0, 2000) : json_encode($response)
        ));
    }
}

// ===========================================================================
// ROUTE: POST stays/adivaha/creds — validate credentials (admin "Test").
// Validates by minting a token (when a Partner ID is given) or calling
// GET /hotels/wallet with the raw key (scope `read`).
// ===========================================================================
$router->post('stays/adivaha/creds', function () use ($db) {

    $start = microtime(true);
    header('Content-Type: application/json');

    $response = [
        'success'  => false,
        'message'  => '',
        'data'     => null,
        'metadata' => [
            'module'      => 'adivaha',
            'service'     => 'stays',
            'provider'    => 'Adivaha B2C Hotels API',
            'api_version' => 'v1',
            'timestamp'   => date('c'),
            'response_time' => 0,
        ],
    ];

    $postKey     = isset($_POST['c1']) ? trim((string) $_POST['c1']) : '';
    $postPartner = isset($_POST['c2']) ? trim((string) $_POST['c2']) : '';
    $postBase    = isset($_POST['c3']) ? trim((string) $_POST['c3']) : '';
    $postEnv     = isset($_POST['env']) ? strtolower(trim((string) $_POST['env'])) : '';

    $stored = getAdivahaHotelCredentials($db);

    $apiKey    = $postKey     !== '' ? $postKey     : ($stored['api_key'] ?? '');
    $partnerId = $postPartner !== '' ? $postPartner : ($stored['partner_id'] ?? '');
    $baseUrl   = $postBase    !== '' ? rtrim($postBase, '/') : ($stored['base_url'] ?? ADIVAHA_HOTEL_DEFAULT_BASE_URL);
    $mode      = $postEnv !== '' ? ($postEnv === 'test' ? 'test' : 'live') : ($stored['mode'] ?? 'live');

    $response['metadata']['environment'] = $mode;

    if ($apiKey === '') {
        $response['message'] = 'API secret key (c1) is required. Enter your Adivaha sk_live_ / sk_test_ key.';
        $response['metadata']['response_time'] = round((microtime(true) - $start) * 1000, 2);
        echo json_encode($response);
        return;
    }

    $credForTest = [
        'api_key' => $apiKey, 'partner_id' => $partnerId, 'base_url' => rtrim($baseUrl, '/'),
        'mode' => $mode, 'debug' => ($mode === 'test'), 'module' => $stored['module'] ?? null,
    ];

    $bearer = '';
    $tokenMinted = false;
    $tokenError = null;
    if ($partnerId !== '') {
        $bearer = adivahaHMintToken($db, $credForTest, true, $tokenError);
        if ($bearer) $tokenMinted = true;
    }
    if (!$bearer) $bearer = $apiKey;

    $walletUrl = rtrim($baseUrl, '/') . '/hotels/wallet';
    $res = adivahaHApiRequest('GET', $walletUrl, null, $bearer, [], 30);
    adivahaHLog('creds/validate', ['url' => $walletUrl, 'mode' => $mode, 'token_minted' => $tokenMinted], $res['raw'], $res['http_code'], $res['curl_error']);

    if ($res['success'] && is_array($res['data'])) {
        $wallet = $res['data'];
        $response['success'] = true;
        $response['message'] = 'Credentials are valid. Connected to Adivaha Hotels ('
            . strtoupper($mode) . ($tokenMinted ? ', 24h token minted' : ', raw-key Bearer') . ').';
        $response['data'] = [
            'connection_status'   => 'connected',
            'authentication'      => ['method' => $tokenMinted ? 'token (POST /token)' : 'raw key as Bearer', 'status' => 'ok'],
            'partner_id_provided' => ($partnerId !== ''),
            'wallet'              => [
                'currency' => $wallet['currency'] ?? null, 'available' => $wallet['available'] ?? null,
                'deposit_balance' => $wallet['deposit_balance'] ?? null, 'status' => $wallet['status'] ?? null,
            ],
            'base_url' => rtrim($baseUrl, '/'), 'mode' => $mode,
        ];
    } else {
        $apiError = adivahaHExtractError($res);
        if ($res['http_code'] === 401) {
            $response['message'] = 'Authentication failed (401): the API key' . ($partnerId !== '' ? '/Partner ID' : '') . ' is invalid or expired. ' . $apiError;
        } elseif ($res['http_code'] === 403) {
            $response['message'] = 'Key is valid but lacks the `read` scope (403): ' . $apiError;
        } elseif ($res['http_code'] === 0) {
            $response['message'] = 'Could not reach Adivaha at ' . rtrim($baseUrl, '/') . '. Check the Base URL / network. ' . $apiError;
        } else {
            $response['message'] = 'Validation failed (HTTP ' . $res['http_code'] . '): ' . $apiError;
        }
        if ($tokenError && $partnerId !== '') $response['message'] .= ' [token mint: ' . $tokenError . ']';
        $response['data'] = ['connection_status' => 'failed', 'http_code' => $res['http_code'], 'mode' => $mode, 'base_url' => rtrim($baseUrl, '/')];
    }

    $response['metadata']['response_time'] = round((microtime(true) - $start) * 1000, 2);
    echo json_encode($response);
});

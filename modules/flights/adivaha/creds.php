<?php
// path: modules/flights/adivaha/creds.php
// Adivaha (backoffice.adivaha.com) B2C Flights API — credential helpers + validation route.
// POST flights/adivaha/creds
//
// Auth model (from the Adivaha OpenAPI spec):
//   • Every call is authenticated with `Authorization: Bearer <token>`.
//   • The token is EITHER a 24h token (bt_...) minted from POST /token using the
//     headers X-Api-Key + X-Partner-Id, OR the raw API key (sk_live_/sk_test_...)
//     used directly as the Bearer value — the spec accepts the raw key "for
//     convenience" (securitySchemes.bearerAuth). We prefer minting a 24h token
//     when a Partner ID is configured (fewer key exposures, matches docs), and
//     fall back to the raw key as Bearer when no Partner ID is present.
//
// Credential column mapping in the `modules` row (name='adivaha', type='flights'):
//   c1 = API secret key (sk_live_... / sk_test_...)
//   c2 = Partner ID (PID) — optional; enables the POST /token mint path
//   c3 = Base URL override — optional; defaults to the Live B2C API base
//   dev_mode: '1' = TEST (expects sk_test_), '0' = LIVE (expects sk_live_)

global $router;

// Default Live base URL (the only server listed in the spec). Admin may override
// it via c3 if Adivaha issues a separate sandbox host.
if (!defined('ADIVAHA_DEFAULT_BASE_URL')) {
    define('ADIVAHA_DEFAULT_BASE_URL', 'https://backoffice.adivaha.com/b2c/api/v1');
}

// ===========================================================================
// HELPERS (shared by search.php / revalidate.php / actions/*)
// ===========================================================================

if (!function_exists('getAdivahaModule')) {
    function getAdivahaModule($db)
    {
        return $db->get('modules', '*', [
            'name' => 'adivaha',
            'type' => 'flights',
        ]);
    }
}

if (!function_exists('getAdivahaCredentials')) {
    /**
     * Load and normalise the Adivaha credentials from the module row. Reads the
     * discrete c1..c3 columns (c1=key, c2=partner id, c3=base url); it also
     * tolerates a legacy JSON `credentials` blob holding the same c1..c3 keys.
     * NOTE: the minted 24h token is cached separately in the `c4` TEXT column
     * (see adivahaMintToken) — NOT in `credentials`, which is enum('1','0').
     *
     * @return array|null {api_key, partner_id, base_url, mode, debug, module} or
     *                    null when the module row is missing.
     */
    function getAdivahaCredentials($db)
    {
        $module = getAdivahaModule($db);
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
        if ($baseUrl === '') $baseUrl = ADIVAHA_DEFAULT_BASE_URL;
        $baseUrl = rtrim($baseUrl, '/');

        return [
            'api_key'    => trim((string) ($creds['c1'] ?? '')),  // sk_live_ / sk_test_
            'partner_id' => trim((string) ($creds['c2'] ?? '')),  // PID (optional)
            'base_url'   => $baseUrl,
            'mode'       => $devMode ? 'test' : 'live',
            'debug'      => $devMode,
            'module'     => $module,
        ];
    }
}

if (!function_exists('adivahaApiRequest')) {
    /**
     * Low-level JSON request to the Adivaha API. Adds the Bearer token, JSON
     * headers, optional Idempotency-Key, and honours the shared supplier timeout
     * constants. Returns a normalised result array.
     *
     * @param string      $method  GET|POST
     * @param string      $url     Fully-qualified URL
     * @param array|null  $payload JSON body for POST (null for GET)
     * @param string      $bearer  Bearer token (24h token OR raw key)
     * @param array       $extraHeaders Extra header strings (e.g. Idempotency-Key)
     * @param int|null    $timeout
     */
    function adivahaApiRequest($method, $url, $payload, $bearer, array $extraHeaders = [], $timeout = null, $connectTimeout = null)
    {
        $requestTimeout = $timeout !== null
            ? (int) $timeout
            : (defined('SUPPLIER_REQUEST_TIMEOUT') ? (int) SUPPLIER_REQUEST_TIMEOUT : 60);
        $connTimeout = $connectTimeout !== null
            ? (int) $connectTimeout
            : (defined('SUPPLIER_CONNECT_TIMEOUT') ? (int) SUPPLIER_CONNECT_TIMEOUT : 10);

        $headers = [
            'Accept: application/json',
        ];
        if ($bearer !== '') {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
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

if (!function_exists('adivahaExtractError')) {
    /**
     * Pull a human-readable error string out of an Adivaha error body
     * ({ "error": "<code>", "message": "..." }) or a transport failure.
     */
    function adivahaExtractError($result)
    {
        if (!empty($result['curl_error'])) {
            return 'Network error: ' . $result['curl_error'];
        }
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

if (!function_exists('adivahaMintToken')) {
    /**
     * Exchange X-Api-Key + X-Partner-Id for a 24h Bearer token via POST /token.
     * Caches the token (with its expiry) in the module's `credentials` JSON so we
     * do not mint one on every request (the docs say: one token per day, reuse it
     * until a 401). Returns the token string, or null on failure (and sets
     * $error). Requires a Partner ID.
     */
    function adivahaMintToken($db, $credentials = null, $forceNew = false, &$error = null)
    {
        if ($credentials === null) $credentials = getAdivahaCredentials($db);
        if (!$credentials || $credentials['api_key'] === '' || $credentials['partner_id'] === '') {
            $error = 'Partner ID not configured — cannot mint a token (will use the raw key as Bearer instead).';
            return null;
        }

        $module   = $credentials['module'] ?? getAdivahaModule($db);
        $cacheKey = 'adivaha_token_' . $credentials['mode'];

        // Token cache lives in the `c4` TEXT column — NOT `credentials`, which is
        // enum('1','0') and would silently reject a JSON write under MySQL strict
        // mode (the PDO here is ERRMODE_SILENT, so such a failure is invisible).
        // c4 is unused for Adivaha creds (only c1/c2/c3 are) and the admin form
        // never writes it, so it is a safe private cache slot.
        if (!$forceNew && $module && !empty($module['c4'])) {
            $stored = json_decode((string) $module['c4'], true) ?: [];
            $cached = is_array($stored) ? ($stored[$cacheKey] ?? null) : null;
            if (is_array($cached)
                && !empty($cached['token'])
                && !empty($cached['expires_at'])
                && strtotime($cached['expires_at']) > (time() + 300)) { // 5-min safety margin
                return (string) $cached['token'];
            }
        }

        // POST /token with the two header credentials. security: [] (no bearer).
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
                : (is_array($data) && !empty($data['message'])
                    ? (string) $data['message']
                    : ('Token mint failed (HTTP ' . $httpCode . ')'));
            return null;
        }

        $token     = (string) $data['token'];
        $expiresAt = !empty($data['expires_at'])
            ? (string) $data['expires_at']
            : date('c', time() + (int) ($data['expires_in'] ?? 86400));

        // Cache it in the `c4` TEXT column (see read note above).
        if ($module) {
            $stored = !empty($module['c4']) ? (json_decode((string) $module['c4'], true) ?: []) : [];
            if (!is_array($stored)) $stored = [];
            $stored[$cacheKey] = [
                'token'      => $token,
                'expires_at' => $expiresAt,
                'minted_at'  => date('c'),
            ];
            try {
                $db->update('modules', ['c4' => json_encode($stored)], ['id' => $module['id']]);
            } catch (Throwable $e) {
                error_log('adivahaMintToken: could not cache token: ' . $e->getMessage());
            }
        }

        return $token;
    }
}

if (!function_exists('adivahaBearer')) {
    /**
     * Resolve the Bearer value for authenticated calls: a 24h minted token when a
     * Partner ID is configured, else the raw API key (accepted by the API). This
     * is the single entry point search/revalidate/actions use to authenticate.
     *
     * @return string Bearer value ('' when no credentials at all).
     */
    function adivahaBearer($db, $credentials = null, $forceNew = false)
    {
        if ($credentials === null) $credentials = getAdivahaCredentials($db);
        if (!$credentials || $credentials['api_key'] === '') return '';

        if ($credentials['partner_id'] !== '') {
            $err = null;
            $token = adivahaMintToken($db, $credentials, $forceNew, $err);
            if ($token) return $token;
            // Mint failed — fall back to the raw key (docs accept it). Log once.
            error_log('adivahaBearer: token mint failed (' . ($err ?? '?') . ') — falling back to raw key as Bearer.');
        }
        return $credentials['api_key'];
    }
}

if (!function_exists('adivahaLog')) {
    /** Log an Adivaha API call without leaking the key/token. */
    function adivahaLog($endpoint, $request, $response, $httpCode, $error = null, $invoiceId = null)
    {
        $safeReq = is_array($request) ? $request : (json_decode((string) $request, true) ?? []);
        error_log(sprintf(
            "[ADIVAHA][%s] endpoint=%s invoice=%s http=%d error=%s\nREQ: %s\nRES: %s",
            date('Y-m-d H:i:s'),
            $endpoint,
            $invoiceId ?? '-',
            (int) $httpCode,
            $error ?? '-',
            is_array($safeReq) ? json_encode($safeReq) : (string) $safeReq,
            is_string($response) ? substr($response, 0, 2000) : json_encode($response)
        ));
    }
}

// ===========================================================================
// ROUTE: POST flights/adivaha/creds — validate credentials
// ---------------------------------------------------------------------------
// Admin-panel "Test Credentials". Validates by either minting a 24h token
// (when a Partner ID is given) or calling GET /flights/wallet with the raw key
// (scope `read`, the cheapest authenticated call). Returns a structured JSON
// result the admin UI renders.
// ===========================================================================
$router->post('flights/adivaha/creds', function () use ($db) {

    $start = microtime(true);

    header('Content-Type: application/json');

    $response = [
        'success'  => false,
        'message'  => '',
        'data'     => null,
        'metadata' => [
            'module'        => 'adivaha',
            'service'       => 'flights',
            'provider'      => 'Adivaha B2C Flights API',
            'api_version'   => 'v1',
            'timestamp'     => date('c'),
            'response_time' => 0,
            'environment'   => 'live',
        ],
    ];

    // Accept credentials from the POST form (admin test) OR fall back to the
    // stored module row. This lets the admin test BEFORE saving.
    $postKey     = isset($_POST['c1']) ? trim((string) $_POST['c1']) : '';
    $postPartner = isset($_POST['c2']) ? trim((string) $_POST['c2']) : '';
    $postBase    = isset($_POST['c3']) ? trim((string) $_POST['c3']) : '';
    $postEnv     = isset($_POST['env']) ? strtolower(trim((string) $_POST['env'])) : '';

    $stored = getAdivahaCredentials($db);

    $apiKey    = $postKey     !== '' ? $postKey     : ($stored['api_key'] ?? '');
    $partnerId = $postPartner !== '' ? $postPartner : ($stored['partner_id'] ?? '');
    $baseUrl   = $postBase    !== '' ? rtrim($postBase, '/') : ($stored['base_url'] ?? ADIVAHA_DEFAULT_BASE_URL);
    $mode      = $postEnv !== '' ? ($postEnv === 'test' ? 'test' : 'live') : ($stored['mode'] ?? 'live');

    $response['metadata']['environment'] = $mode;

    if ($apiKey === '') {
        $response['message'] = 'API secret key (c1) is required. Enter your Adivaha sk_live_ / sk_test_ key.';
        $response['metadata']['response_time'] = round((microtime(true) - $start) * 1000, 2);
        echo json_encode($response);
        return;
    }

    // Soft sanity check on the key prefix vs the chosen mode (non-fatal warning).
    $keyLooksTest = (strpos($apiKey, 'sk_test_') === 0);
    $keyLooksLive = (strpos($apiKey, 'sk_live_') === 0);
    $modeWarning  = '';
    if ($mode === 'test' && $keyLooksLive) {
        $modeWarning = 'Mode is TEST but the key looks like a LIVE key (sk_live_). Double-check before enabling.';
    } elseif ($mode === 'live' && $keyLooksTest) {
        $modeWarning = 'Mode is LIVE but the key looks like a TEST key (sk_test_). Double-check before enabling.';
    }

    $credForTest = [
        'api_key'    => $apiKey,
        'partner_id' => $partnerId,
        'base_url'   => rtrim($baseUrl, '/'),
        'mode'       => $mode,
        'debug'      => ($mode === 'test'),
        'module'     => $stored['module'] ?? null,
    ];

    $bearer      = '';
    $tokenMinted = false;
    $tokenError  = null;

    // Path 1: Partner ID present → try minting a 24h token (forced fresh for the test).
    if ($partnerId !== '') {
        $bearer = adivahaMintToken($db, $credForTest, true, $tokenError);
        if ($bearer) {
            $tokenMinted = true;
        }
    }
    // Path 2: no Partner ID, or mint failed → use the raw key as Bearer.
    if (!$bearer) {
        $bearer = $apiKey;
    }

    // Validate the bearer with the cheapest authenticated read: GET /flights/wallet.
    $walletUrl = rtrim($baseUrl, '/') . '/flights/wallet';
    $res = adivahaApiRequest('GET', $walletUrl, null, $bearer, [], 30);

    adivahaLog('creds/validate', ['url' => $walletUrl, 'mode' => $mode, 'token_minted' => $tokenMinted], $res['raw'], $res['http_code'], $res['curl_error']);

    if ($res['success'] && is_array($res['data'])) {
        $wallet = $res['data'];
        $response['success'] = true;
        $response['message'] = 'Credentials are valid. Connected to Adivaha ('
            . strtoupper($mode) . ($tokenMinted ? ', 24h token minted' : ', raw-key Bearer') . ').';
        $response['data'] = [
            'connection_status'   => 'connected',
            'authentication'      => [
                'method' => $tokenMinted ? 'token (POST /token)' : 'raw key as Bearer',
                'status' => 'ok',
            ],
            'partner_id_provided' => ($partnerId !== ''),
            'wallet'              => [
                'currency'        => $wallet['currency']        ?? null,
                'available'       => $wallet['available']       ?? null,
                'deposit_balance' => $wallet['deposit_balance'] ?? null,
                'credit_limit'    => $wallet['credit_limit']    ?? null,
                'status'          => $wallet['status']          ?? null,
            ],
            'base_url'            => rtrim($baseUrl, '/'),
            'mode'                => $mode,
        ];
        if ($modeWarning !== '') {
            $response['data']['warning'] = $modeWarning;
        }
    } else {
        // 401 → bad key/scope; 403 → key lacks `read`; other → transport/base-url.
        $apiError = adivahaExtractError($res);
        if ($res['http_code'] === 401) {
            $response['message'] = 'Authentication failed (401): the API key' . ($partnerId !== '' ? '/Partner ID' : '') . ' is invalid or expired. ' . $apiError;
        } elseif ($res['http_code'] === 403) {
            $response['message'] = 'Key is valid but lacks the `read` scope (403): ' . $apiError;
        } elseif ($res['http_code'] === 0) {
            $response['message'] = 'Could not reach Adivaha at ' . rtrim($baseUrl, '/') . '. Check the Base URL / network. ' . $apiError;
        } else {
            $response['message'] = 'Validation failed (HTTP ' . $res['http_code'] . '): ' . $apiError;
        }
        if ($tokenError && $partnerId !== '') {
            $response['message'] .= ' [token mint: ' . $tokenError . ']';
        }
        $response['data'] = [
            'connection_status' => 'failed',
            'http_code'         => $res['http_code'],
            'mode'              => $mode,
            'base_url'          => rtrim($baseUrl, '/'),
        ];
        if ($modeWarning !== '') {
            $response['data']['warning'] = $modeWarning;
        }
    }

    $response['metadata']['response_time'] = round((microtime(true) - $start) * 1000, 2);
    echo json_encode($response);
});

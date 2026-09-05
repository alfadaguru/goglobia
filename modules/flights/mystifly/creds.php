<?php
// path: modules/flights/mystifly/creds.php
// Mystifly credential validation route
// POST flights/mystifly/creds

global $router;

// ===========================================================================
// HELPERS
// ===========================================================================

function getMystiflyModule($db)
{
    return $db->get('modules', '*', [
        'name' => 'mystifly',
        'type' => 'flights'
    ]);
}

function getMystiflyCredentials($db)
{
    $module = getMystiflyModule($db);
    if (!$module) return null;

    $creds = [];

    if (!empty($module['credentials'])) {
        $parsed = json_decode($module['credentials'], true);
        if (is_array($parsed)) {
            $creds = $parsed;
        }
    }

    // Fallback to individual columns
    foreach (['c1','c2','c3','c4','c5'] as $col) {
        if (empty($creds[$col]) && !empty($module[$col])) {
            $creds[$col] = $module[$col];
        }
    }

    // Environment comes from dev_mode (0=production/live, 1=development/demo)
    $environment = (isset($module['dev_mode']) && $module['dev_mode'] == '1') ? 'demo' : 'live';

    return [
        'account_number' => $creds['c1'] ?? '',   // MCN / Account Number
        'username'       => $creds['c2'] ?? '',
        'password'       => $creds['c3'] ?? '',
        'session_id'     => $creds['c4'] ?? '',    // Bearer Token / SessionID
        'base_url'       => $creds['c5'] ?? '',
        'environment'    => $environment,
        'debug'          => $module['dev_mode'] == '1',
        'module'         => $module,
    ];
}

function getMystiflyBaseUrl($credentials)
{
    if (!empty($credentials['base_url'])) {
        return rtrim($credentials['base_url'], '/');
    }
    $env = strtolower($credentials['environment'] ?? 'demo');
    if ($env === 'live' || $env === 'production') {
        return 'https://api.myfarebox.com'; // TODO: confirm live URL from Mystifly docs
    }
    return 'https://restapidemo.myfarebox.com';
}

/**
 * Call CreateSession and return the SessionID string, or null on failure.
 */
function mystiflyCreateSession($accountNumber, $username, $password, $baseUrl, &$rawResponse = null)
{
    $payload = json_encode([
        'AccountNumber' => $accountNumber,
        'UserName'      => $username,
        'Password'      => $password,
        'Target'        => 'Test', // TODO: pass 'Live' for production
    ]);

    $ch = curl_init($baseUrl . '/api/CreateSession');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $result   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $rawResponse = $result;

    if ($curlErr) {
        // Store error info so caller can surface it
        $rawResponse = json_encode(['_curl_error' => $curlErr, '_http_code' => $httpCode]);
        return null;
    }

    if ($httpCode !== 200) {
        $rawResponse = json_encode(['_http_code' => $httpCode, '_body' => $result]);
        return null;
    }

    $data = json_decode($result, true);
    // Mystifly returns { "Success": true, "Data": { "SessionId": "..." } }
    if (!empty($data['Data']['SessionId']) && ($data['Success'] ?? false) === true) {
        return $data['Data']['SessionId'];
    }
    // Legacy format fallback: { "SessionId": "...", "Status": { "Code": "001" } }
    if (!empty($data['SessionId']) && ($data['Status']['Code'] ?? '') === '001') {
        return $data['SessionId'];
    }
    return null;
}

/**
 * Retrieve (or create) a valid Bearer token from the module credentials.
 * Saves a newly created session back to the DB so subsequent requests reuse it.
 */
function getMystiflyBearerToken($db, $credentials = null)
{
    if ($credentials === null) {
        $credentials = getMystiflyCredentials($db);
    }
    if (!$credentials) return null;

    // 1. Use stored session_id if available
    if (!empty($credentials['session_id'])) {
        return $credentials['session_id'];
    }

    // 2. Create a fresh session
    $baseUrl   = getMystiflyBaseUrl($credentials);
    $sessionId = mystiflyCreateSession(
        $credentials['account_number'],
        $credentials['username'],
        $credentials['password'],
        $baseUrl
    );

    if ($sessionId) {
        // Persist back to module row so next request can reuse it
        $module = $credentials['module'] ?? getMystiflyModule($db);
        if ($module) {
            $existingCreds = [];
            if (!empty($module['credentials'])) {
                $existingCreds = json_decode($module['credentials'], true) ?? [];
            }
            $existingCreds['c4'] = $sessionId;
            $db->update('modules', ['credentials' => json_encode($existingCreds)], ['id' => $module['id']]);
        }
    }

    return $sessionId;
}

/**
 * Make a Mystifly API call, with one automatic session-refresh retry.
 */
function mystiflyApiRequest($db, $endpoint, $payload = [], $method = 'POST', $booking_id = null, $timeout = null)
{
    $credentials = getMystiflyCredentials($db);
    if (!$credentials) {
        return ['success' => false, 'error' => 'Mystifly module not configured', 'http_code' => 0, 'data' => null];
    }

    $baseUrl = getMystiflyBaseUrl($credentials);
    $token   = getMystiflyBearerToken($db, $credentials);

    $fullUrl = $baseUrl . '/' . ltrim($endpoint, '/');
    $result  = _mystiflyDoRequest($fullUrl, $payload, $method, $token, $timeout);

    // If 401 / session expired, refresh once and retry
    if (($result['http_code'] === 401) || ($result['data']['Status']['Code'] ?? '') === 'SE') {
        $newToken = mystiflyCreateSession(
            $credentials['account_number'],
            $credentials['username'],
            $credentials['password'],
            $baseUrl
        );
        if ($newToken) {
            $module = getMystiflyModule($db);
            if ($module) {
                $existingCreds = !empty($module['credentials']) ? (json_decode($module['credentials'], true) ?? []) : [];
                $existingCreds['c4'] = $newToken;
                $db->update('modules', ['credentials' => json_encode($existingCreds)], ['id' => $module['id']]);
            }
            $result = _mystiflyDoRequest($fullUrl, $payload, $method, $newToken, $timeout);
        }
    }

    mystiflyLog($db, $endpoint, $payload, $result['raw'], $result['http_code'], $result['curl_error'] ?? null, $booking_id);

    return $result;
}

function _mystiflyDoRequest($url, $payload, $method, $token, $timeout = null)
{
    $ch = curl_init($url);
    $requestTimeout = $timeout !== null
        ? (int) $timeout
        : (defined('SUPPLIER_REQUEST_TIMEOUT') ? (int) SUPPLIER_REQUEST_TIMEOUT : 120);
    if ($requestTimeout < 1) {
        $requestTimeout = 120;
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $requestTimeout,
        CURLOPT_CONNECTTIMEOUT => defined('SUPPLIER_CONNECT_TIMEOUT')  ? (int) SUPPLIER_CONNECT_TIMEOUT  : 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ];

    $body = is_array($payload) ? json_encode($payload) : $payload;

    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = $body;
    } else {
        $opts[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
        if (!empty($payload)) $opts[CURLOPT_POSTFIELDS] = $body;
    }

    curl_setopt_array($ch, $opts);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $data = json_decode($raw, true);

    return [
        'success'    => ($httpCode >= 200 && $httpCode < 300 && !$curlErr),
        'http_code'  => $httpCode,
        'raw'        => $raw,
        'data'       => $data,
        'curl_error' => $curlErr ?: null,
    ];
}

/**
 * Log a Mystifly API call. Masks sensitive fields.
 */
function mystiflyLog($db, $endpoint, $request, $response, $httpCode, $error = null, $booking_id = null)
{
    // Mask password and token
    $safeReq = is_array($request) ? $request : (json_decode($request, true) ?? []);
    if (isset($safeReq['Password'])) $safeReq['Password'] = '***';
    if (isset($safeReq['SessionId'])) $safeReq['SessionId'] = '***';

    $logLine = sprintf(
        "[MYSTIFLY][%s] endpoint=%s booking_id=%s http=%d error=%s\nREQ: %s\nRES: %s",
        date('Y-m-d H:i:s'),
        $endpoint,
        $booking_id ?? '-',
        $httpCode,
        $error ?? '-',
        json_encode($safeReq),
        is_string($response) ? substr($response, 0, 2000) : json_encode($response)
    );

    error_log($logLine);

    // If a booking_id is provided, append a summary to booking_data error_response
    if ($booking_id) {
        $booking = $db->get('bookings', ['booking_data', 'error_response'], ['invoice_id' => $booking_id]);
        if ($booking) {
            $bd = json_decode($booking['booking_data'] ?? '{}', true) ?? [];
            $bd['mystifly_last_log'] = [
                'endpoint'  => $endpoint,
                'http_code' => $httpCode,
                'timestamp' => date('Y-m-d H:i:s'),
                'error'     => $error,
            ];
            $db->update('bookings', ['booking_data' => json_encode($bd)], ['invoice_id' => $booking_id]);
        }
    }
}

// ===========================================================================
// ROUTE: POST flights/mystifly/creds
// ===========================================================================
$router->post('flights/mystifly/creds', function () use ($db) {

    $start = microtime(true);

    $response = [
        'success'  => false,
        'message'  => '',
        'data'     => null,
        'metadata' => [
            'module'        => 'mystifly',
            'service'       => 'flights',
            'provider'      => 'Mystifly',
            'timestamp'     => date('c'),
            'response_time' => 0,
            'environment'   => 'demo',
        ],
        'debug' => [
            'endpoint_used'    => '',
            'request_method'   => 'POST',
            'validation_steps' => [],
            'api_response'     => null,
        ],
    ];

    try {
        $accountNumber = trim($_POST['c1'] ?? '');
        $username      = trim($_POST['c2'] ?? '');
        $password      = trim($_POST['c3'] ?? '');
        $sessionId     = trim($_POST['c4'] ?? '');
        $baseUrlInput  = trim($_POST['c5'] ?? '');
        // Environment comes from dev_mode select box, not a credential field
        $devMode = trim($_POST['dev_mode'] ?? $_POST['hidden_dev_mode'] ?? '1');
        $env     = ($devMode === '0') ? 'live' : 'demo';

        $response['metadata']['environment'] = $env;

        $baseUrl = !empty($baseUrlInput) ? rtrim($baseUrlInput, '/') : 'https://restapidemo.myfarebox.com';
        $response['debug']['endpoint_used'] = $baseUrl . '/CreateSession';

        // Require either (username+password+account) or a sessionId to test
        if (empty($accountNumber) && empty($sessionId)) {
            $response['message'] = 'Account Number (c1) or Session ID (c4) is required';
            echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $response['debug']['validation_steps'][] = 'Required fields present';

        // -- Attempt CreateSession if credentials provided --
        if (!empty($accountNumber) && !empty($username) && !empty($password)) {
            $response['debug']['validation_steps'][] = 'Calling Mystifly CreateSession';

            $rawResponse = null;
            $newSessionId = mystiflyCreateSession($accountNumber, $username, $password, $baseUrl, $rawResponse);

            $parsedRaw = json_decode($rawResponse ?? '', true) ?? [];
            $response['debug']['api_response'] = $parsedRaw;

            if ($newSessionId) {
                $response['success'] = true;
                $response['message'] = 'Mystifly credentials validated. Session created successfully.';
                $response['data'] = [
                    'connection_status' => 'connected',
                    'authentication'    => [
                        'status'         => 'authenticated',
                        'session_prefix' => substr($newSessionId, 0, 8) . '...',
                        'token_type'     => 'Bearer',
                    ],
                    'api_details' => [
                        'provider'    => 'Mystifly',
                        'environment' => $env,
                        'base_url'    => $baseUrl,
                        'swagger'     => 'https://restapidemo.myfarebox.com/index.html',
                        'docs'        => 'https://rover.myfarebox.com',
                    ],
                    'session' => [
                        'session_id'     => substr($newSessionId, 0, 8) . '...',
                        'created_at'     => date('Y-m-d H:i:s'),
                        'save_as'        => 'c4 in module settings to reuse',
                    ],
                ];
                $response['debug']['validation_steps'][] = 'CreateSession succeeded';
            } else {
                $parsedRaw = json_decode($rawResponse ?? '', true);

                // Surface cURL error if present
                if (!empty($parsedRaw['_curl_error'])) {
                    $response['message'] = 'Connection failed: ' . $parsedRaw['_curl_error'];
                    $response['debug']['validation_steps'][] = 'cURL error: ' . $parsedRaw['_curl_error'];
                } elseif (!empty($parsedRaw['_http_code'])) {
                    $response['message'] = 'CreateSession failed — HTTP ' . $parsedRaw['_http_code'];
                    $response['debug']['validation_steps'][] = 'HTTP error: ' . $parsedRaw['_http_code'];
                } else {
                    $response['message'] = 'Mystifly CreateSession failed. Check account number, username, and password.';
                    $response['debug']['validation_steps'][] = 'CreateSession failed';
                }

                $response['debug']['api_response'] = $parsedRaw;
                $response['data'] = [
                    'error_type'     => 'authentication_error',
                    'status_code'    => $parsedRaw['Status']['Code'] ?? ($parsedRaw['_http_code'] ?? 'unknown'),
                    'status_message' => $parsedRaw['Status']['Message'] ?? ($parsedRaw['_curl_error'] ?? 'Unknown error'),
                    'raw_response'   => $parsedRaw,
                    'troubleshooting' => [
                        'Verify Account Number (MCN)',
                        'Verify Username and Password',
                        'Check environment (demo vs live)',
                        'Confirm base URL: ' . $baseUrl,
                    ],
                ];
            }

        } elseif (!empty($sessionId)) {
            // Only session ID provided – do a lightweight ping (Revalidate with dummy FareSourceCode will 401 if expired)
            $response['debug']['validation_steps'][] = 'Testing provided SessionID';
            $response['debug']['endpoint_used'] = $baseUrl . '/Revalidate (session test)';

            // A lightweight call: try GetFareRules with a dummy FSC – a 200 with a structured error means auth is valid
            $testResult = _mystiflyDoRequest($baseUrl . '/api/v1/Revalidate/Flight', ['FareSourceCode' => 'TEST', 'Target' => 'Test', 'ConversationId' => 'ping'], 'POST', $sessionId);

            if ($testResult['http_code'] === 401) {
                $response['message'] = 'Session ID is expired or invalid';
                $response['debug']['validation_steps'][] = 'SessionID rejected (401)';
            } else {
                $response['success'] = true;
                $response['message'] = 'Session ID appears valid (API reachable)';
                $response['data'] = [
                    'connection_status' => 'connected',
                    'authentication'    => ['status' => 'session_active', 'token_type' => 'Bearer'],
                    'session'           => ['session_prefix' => substr($sessionId, 0, 8) . '...'],
                ];
                $response['debug']['validation_steps'][] = 'SessionID accepted';
            }
        }

    } catch (Throwable $e) {
        $response['message'] = 'An error occurred during credential validation';
        $response['debug']['validation_steps'][] = 'Exception: ' . $e->getMessage();
    }

    $response['metadata']['response_time'] = round((microtime(true) - $start) * 1000) . 'ms';
    header('Content-Type: application/json');
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
});

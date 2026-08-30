<?php
// ============================================================================
// MOZIO - CREDENTIAL VALIDATION ENDPOINT
// ============================================================================
// Validates the API-KEY by calling the amenities list endpoint, which needs
// no search parameters and returns 403 on an invalid/missing key.
//
// ENDPOINT: POST /cars/mozio/creds
// ============================================================================

global $router;

$router->post('cars/mozio/creds', function() {
    set_time_limit(30);
    $start_time = microtime(true);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $response = [
        'success' => false,
        'message' => '',
        'data' => null,
        'metadata' => [
            'module' => 'mozio',
            'service' => 'cars',
            'provider' => 'Mozio Ground Transportation API',
            'api_version' => 'v2',
            'timestamp' => date('c'),
            'response_time_ms' => 0,
            'environment' => 'production'
        ],
        'debug' => [
            'validation_steps' => [],
            'api_request' => null,
            'api_response' => null,
            'endpoint_used' => null,
            'request_method' => 'REST'
        ]
    ];

    try {
        $response['debug']['validation_steps'][] = '[START] Starting Mozio credential validation';

        $api_key  = trim($_POST['c1'] ?? '');
        $env      = trim($_POST['env'] ?? 'production');
        $base_url = ($env === 'test') ? 'https://api-testing.mozio.com' : 'https://api.mozio.com';
        $response['metadata']['environment'] = ($env === 'test') ? 'testing' : 'production';

        if (empty($api_key)) {
            throw new Exception('Missing required credential: API Key (c1)');
        }

        if ($api_key === 'test') {
            $response['message'] = 'Test credentials provided - cannot validate with Mozio';
            $response['data'] = [
                'error_type' => 'test_credentials',
                'error_code' => 'test_values_not_allowed',
                'error_description' => 'Cannot validate with test credentials.',
                'expected_format' => [
                    'c1' => 'Your Mozio API Key',
                ]
            ];
            $response['metadata']['response_time_ms'] = round((microtime(true) - $start_time) * 1000, 2);
            http_response_code(400);
            echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return;
        }

        $response['debug']['validation_steps'][] = '[OK] API Key provided: ' . substr($api_key, 0, 6) . '...';

        $url = $base_url . '/v2/amenities/';
        $response['debug']['endpoint_used'] = $url;
        $response['debug']['api_request'] = 'GET ' . $url;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'API-KEY: ' . $api_key,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        ]);

        $api_response = curl_exec($ch);
        $http_code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error   = curl_error($ch);
        $total_time   = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_close($ch);

        $response['debug']['validation_steps'][] = "[RESPONSE] HTTP {$http_code} (total: " . round($total_time, 3) . "s)";

        if ($curl_error) {
            $response['debug']['validation_steps'][] = '[ERROR] Network: ' . $curl_error;
            throw new Exception('Network Error: ' . $curl_error);
        }

        $response['debug']['api_response'] = substr($api_response, 0, 500) . (strlen($api_response) > 500 ? '...' : '');
        $api_data = json_decode($api_response, true);

        if ($http_code === 200 && is_array($api_data)) {
            $response['debug']['validation_steps'][] = '[SUCCESS] Credentials valid — ' . count($api_data) . ' amenities returned';
            $response['success'] = true;
            $response['message'] = 'Mozio API credentials validated successfully';
            $response['data'] = [
                'connection_status' => 'connected',
                'authentication' => [
                    'status'      => 'authenticated',
                    'auth_type'   => 'API-KEY header',
                    'environment' => $response['metadata']['environment'],
                    'key_preview' => substr($api_key, 0, 6) . '...'
                ],
                'api_details' => [
                    'provider'      => 'Mozio',
                    'api_version'   => 'v2',
                    'endpoint'      => $base_url,
                    'response_time' => round($total_time * 1000, 2) . 'ms',
                    'amenities_available' => count($api_data),
                    'supported_services' => [
                        'Shuttles', 'Limos', 'Taxis', 'Airporters', 'Buses', 'Trains'
                    ]
                ]
            ];
        } elseif ($http_code === 403) {
            throw new Exception('Authentication failed — invalid or missing API key (HTTP 403)');
        } else {
            throw new Exception("API returned HTTP {$http_code}: " . substr($api_response, 0, 200));
        }

    } catch (Exception $e) {
        $response['debug']['validation_steps'][] = '[ERROR] ' . $e->getMessage();

        if (empty($response['data'])) {
            $response['data'] = [
                'error_type'        => 'validation_error',
                'error_code'        => 'credential_validation_failed',
                'error_description' => $e->getMessage(),
                'quick_fixes' => [
                    'Verify your Mozio API Key (c1) is correct',
                    'Confirm you are using the right key for the selected environment (testing vs production)',
                    'Contact the Mozio team if you do not have API keys yet',
                ]
            ];
        }

        if (empty($response['message'])) {
            $response['message'] = 'Mozio API credential validation failed';
        }
    }

    $response['metadata']['response_time_ms'] = round((microtime(true) - $start_time) * 1000, 2);
    $response['debug']['validation_steps'][] = "[TIME] Total: {$response['metadata']['response_time_ms']}ms";

    http_response_code($response['success'] ? 200 : 400);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
});

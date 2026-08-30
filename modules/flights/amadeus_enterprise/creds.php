<?php

global $router;

$router->post('flights/amadeus_enterprise/creds', function() use ($db) {

// Start timing for performance metrics
$start_time = microtime(true);

// Initialize standardized response array
$response = [
    'success' => false,
    'message' => '',
    'data' => null,
    'metadata' => [
        'module' => 'amadeus_enterprise',
        'service' => 'flights',
        'provider' => 'Amadeus Enterprise',
        'api_version' => 'v1',
        'timestamp' => date('c'),
        'response_time_ms' => 0,
        'environment' => 'test'
    ],
    'debug' => [
        'endpoint_used' => '',
        'request_method' => 'POST',
        'validation_steps' => [],
        'api_response' => null,
        'raw_response' => null
    ]
];

try {
    $response['debug']['validation_steps'][] = 'Starting credential validation process';

    // Validate required parameters
    if (!isset($_POST['c1']) || empty(trim($_POST['c1']))) {
        $response['message'] = 'Client ID (c1) is required for Amadeus Enterprise API';
        $response['debug']['validation_steps'][] = 'Validation failed: Missing Client ID';
        echo json_encode($response);
        exit;
    }

    if (!isset($_POST['c2']) || empty(trim($_POST['c2']))) {
        $response['message'] = 'Client Secret (c2) is required for Amadeus Enterprise API';
        $response['debug']['validation_steps'][] = 'Validation failed: Missing Client Secret';
        echo json_encode($response);
        exit;
    }

    $response['debug']['validation_steps'][] = 'Required credentials provided';

    // Check for environment mode (optional, defaults to test)
    $env = isset($_POST['env']) ? $_POST['env'] : 'test';
    $response['metadata']['environment'] = $env;

    // Align with search/issue: Enterprise travel.api host first, Self-Service host fallback.
    if ($env === 'production' || $env === 'live') {
        $endpointCandidates = [
            ['v1' => 'https://travel.api.amadeus.com/v1/', 'v2' => 'https://travel.api.amadeus.com/v2/', 'label' => 'production_enterprise'],
            ['v1' => 'https://api.amadeus.com/v1/', 'v2' => 'https://api.amadeus.com/v2/', 'label' => 'production_self_service']
        ];
        $response['debug']['validation_steps'][] = 'Environment: Production endpoints selected (enterprise + self-service fallback)';
    } else {
        $endpointCandidates = [
            ['v1' => 'https://test.travel.api.amadeus.com/v1/', 'v2' => 'https://test.travel.api.amadeus.com/v2/', 'label' => 'test_enterprise'],
            ['v1' => 'https://test.api.amadeus.com/v1/', 'v2' => 'https://test.api.amadeus.com/v2/', 'label' => 'test_self_service']
        ];
        $response['debug']['validation_steps'][] = 'Environment: Test endpoints selected (enterprise + self-service fallback)';
    }

    $end_pointv1 = $endpointCandidates[0]['v1'];
    $end_pointv2 = $endpointCandidates[0]['v2'];
    $response['debug']['endpoint_used'] = $end_pointv1 . 'security/oauth2/token';

    // Extract credentials
    $grant_type = "client_credentials";
    $client_id = trim($_POST['c1']);
    $client_secret = trim($_POST['c2']);

    // Compare tested form credentials with persisted module credentials used by search.php
    $maskValue = static function(string $value): string {
        $len = strlen($value);
        if ($len === 0) {
            return '';
        }
        return substr($value, 0, min(4, $len)) . '...len=' . $len;
    };

    $fingerprint = static function(string $value): string {
        if ($value === '') {
            return '';
        }
        return substr(sha1($value), 0, 12);
    };

    try {
        $savedModule = $db->get('modules', ['id', 'c1', 'c2', 'status', 'active', 'dev_mode'], [
            'name' => 'amadeus_enterprise',
            'type' => 'flights'
        ]);

        if (!empty($savedModule)) {
            $savedC1 = trim((string)($savedModule['c1'] ?? ''));
            $savedC2 = trim((string)($savedModule['c2'] ?? ''));
            $isSynced = hash_equals($savedC1, $client_id) && hash_equals($savedC2, $client_secret);

            $response['debug']['module_sync'] = [
                'module_id' => $savedModule['id'] ?? null,
                'status' => $savedModule['status'] ?? null,
                'active' => $savedModule['active'] ?? null,
                'dev_mode' => $savedModule['dev_mode'] ?? null,
                'is_synced_with_form' => $isSynced,
                'saved' => [
                    'c1_masked' => $maskValue($savedC1),
                    'c2_masked' => $maskValue($savedC2),
                    'c1_fingerprint' => $fingerprint($savedC1),
                    'c2_fingerprint' => $fingerprint($savedC2)
                ],
                'tested' => [
                    'c1_masked' => $maskValue($client_id),
                    'c2_masked' => $maskValue($client_secret),
                    'c1_fingerprint' => $fingerprint($client_id),
                    'c2_fingerprint' => $fingerprint($client_secret)
                ]
            ];

            if ($isSynced) {
                $response['debug']['validation_steps'][] = 'Configuration sync check: Tested credentials match saved module credentials';
            } else {
                $response['debug']['validation_steps'][] = 'Configuration sync warning: Tested credentials differ from saved module credentials used by search';
            }
        } else {
            $response['debug']['validation_steps'][] = 'Configuration sync warning: amadeus_enterprise module row not found for comparison';
        }
    } catch (\Throwable $syncEx) {
        $response['debug']['validation_steps'][] = 'Configuration sync check failed: ' . $syncEx->getMessage();
    }

    $response['debug']['validation_steps'][] = 'Initiating OAuth2 token request to Amadeus API';

    // Test authentication with Amadeus API (try endpoint fallbacks)
    $result = null;
    $http_code = 0;
    $curl_error = '';
    $curl_info = [];
    $successfulEndpoint = null;
    $attemptedEndpoints = [];

    foreach ($endpointCandidates as $candidate) {
        $tokenUrl = $candidate['v1'] . 'security/oauth2/token';
        $attemptedEndpoints[] = $tokenUrl;
        $response['debug']['validation_steps'][] = 'Trying endpoint: ' . $tokenUrl;

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $tokenUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS => "grant_type=" . $grant_type . "&client_id=" . $client_id . "&client_secret=" . $client_secret,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Amadeus-Test/1.0'
        ]);

        $result = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);
        $curl_info = curl_getinfo($curl);
        curl_close($curl);

        $response['debug']['validation_steps'][] = 'Attempt result [' . $candidate['label'] . ']: HTTP ' . $http_code;

        if ($curl_error) {
            $response['debug']['validation_steps'][] = 'cURL error on [' . $candidate['label'] . ']: ' . $curl_error;
            continue;
        }

        $attemptTokenData = json_decode((string)$result, true);
        if ($http_code === 200 && isset($attemptTokenData['access_token'])) {
            $successfulEndpoint = $candidate;
            break;
        }
    }

    if ($successfulEndpoint) {
        $end_pointv1 = $successfulEndpoint['v1'];
        $end_pointv2 = $successfulEndpoint['v2'];
        $response['debug']['endpoint_used'] = $end_pointv1 . 'security/oauth2/token';
    }

    // Store raw response for debugging
    $response['debug']['raw_response'] = $result;
    $response['debug']['attempted_endpoints'] = $attemptedEndpoints;
    $response['debug']['validation_steps'][] = "API request completed with HTTP code: {$http_code}";

    // Check for cURL errors
    if ($curl_error) {
        $response['message'] = 'Network connection error occurred';
        $response['data'] = [
            'error_type' => 'network_error',
            'error_code' => 'CURL_ERROR',
            'error_description' => $curl_error,
            'connection_details' => [
                'endpoint' => $end_pointv1 . 'security/oauth2/token',
                'timeout' => 30,
                'connect_timeout' => 10,
                'ssl_verify' => false
            ],
            'troubleshooting' => [
                'Check internet connection',
                'Verify firewall settings',
                'Confirm DNS resolution',
                'Test endpoint accessibility'
            ]
        ];
        $response['debug']['validation_steps'][] = 'Connection failed: ' . $curl_error;
        echo json_encode($response);
        exit;
    }

    // Parse the response
    $token_data = json_decode($result, true);
    $response['debug']['api_response'] = $token_data;

    if ($http_code === 200 && isset($token_data['access_token'])) {
        // Success - credentials are valid
        $response['success'] = true;
        $response['message'] = 'Amadeus Enterprise API credentials validated successfully';
        $response['data'] = [
            'connection_status' => 'connected',
            'authentication' => [
                'status' => 'authenticated',
                'token_type' => $token_data['type'] ?? 'Bearer',
                'expires_in' => $token_data['expires_in'] ?? 1799,
                'token_scope' => $token_data['scope'] ?? 'default',
                'application_name' => $token_data['application_name'] ?? 'Unknown'
            ],
            'api_details' => [
                'provider' => 'Amadeus Enterprise',
                'environment' => $env,
                'endpoint_v1' => $end_pointv1,
                'endpoint_v2' => $end_pointv2,
                'supported_services' => [
                    'Flight Search',
                    'Flight Offers',
                    'Flight Booking',
                    'Airport Information',
                    'Airline Information'
                ]
            ],
            'account_info' => [
                'client_id' => substr($client_id, 0, 8) . '...',
                'validated_at' => date('Y-m-d H:i:s'),
                'validation_method' => 'OAuth2 Client Credentials'
            ],
            'rate_limits' => [
                'note' => 'Rate limits apply based on your Amadeus subscription',
                'documentation' => 'https://developers.amadeus.com/get-started/rate-limits'
            ]
        ];
        $response['debug']['validation_steps'][] = 'Authentication successful - Valid access token received';

    } elseif ($http_code === 401) {
        // Invalid credentials
        $response['message'] = 'Authentication failed - Invalid credentials provided';
        $response['data'] = [
            'error_type' => 'authentication_error',
            'error_code' => $token_data['error'] ?? 'invalid_client',
            'error_description' => $token_data['error_description'] ?? 'The client credentials are invalid',
            'api_response' => $token_data,
            'provider_details' => [
                'provider' => 'Amadeus Enterprise',
                'environment' => $env,
                'endpoint' => $end_pointv1,
                'attempted_endpoints' => $attemptedEndpoints,
                'documentation' => 'https://developers.amadeus.com/get-started/authentication'
            ],
            'troubleshooting' => [
                'Verify your Client ID is correct',
                'Check your Client Secret is valid',
                'Ensure credentials are for the correct environment',
                'Confirm your application is activated',
                'Check if credentials have expired'
            ],
            'support' => [
                'amadeus_support' => 'https://developers.amadeus.com/support',
                'documentation' => 'https://developers.amadeus.com/get-started'
            ]
        ];
        $response['debug']['validation_steps'][] = 'Authentication failed - Invalid credentials';

    } elseif ($http_code === 400) {
        // Bad request
        $response['message'] = 'Bad request - Invalid request format or parameters';
        $response['data'] = [
            'error_type' => 'request_error',
            'error_code' => $token_data['error'] ?? 'invalid_request',
            'error_description' => $token_data['error_description'] ?? 'The request is malformed',
            'api_response' => $token_data,
            'request_details' => [
                'method' => 'POST',
                'content_type' => 'application/x-www-form-urlencoded',
                'grant_type' => $grant_type,
                'endpoint' => $end_pointv1 . 'security/oauth2/token'
            ],
            'troubleshooting' => [
                'Check request format',
                'Verify all required parameters are provided',
                'Ensure content-type is correct',
                'Review parameter naming'
            ]
        ];
        $response['debug']['validation_steps'][] = 'Request validation failed - Bad request format';

    } elseif ($http_code >= 500) {
        // Server error
        $response['message'] = 'Amadeus API server error - Service temporarily unavailable';
        $response['data'] = [
            'error_type' => 'server_error',
            'error_code' => 'HTTP_' . $http_code,
            'error_description' => 'Amadeus API server returned an error',
                'http_details' => [
                    'status_code' => $http_code,
                    'status_text' => getHttpStatusText($http_code),
                    'response_body' => $result
                ],
            'api_response' => $token_data,
            'provider_status' => [
                'provider' => 'Amadeus Enterprise',
                'status_page' => 'https://status.amadeus.com/',
                'support' => 'https://developers.amadeus.com/support'
            ],
            'troubleshooting' => [
                'Check Amadeus API status page',
                'Retry request after some time',
                'Contact Amadeus support if issue persists',
                'Verify your API subscription status'
            ]
        ];
        $response['debug']['validation_steps'][] = "Server error received - HTTP {$http_code}";

    } elseif ($http_code === 429) {
        // Rate limit exceeded
        $response['message'] = 'Rate limit exceeded - Too many requests';
        $response['data'] = [
            'error_type' => 'rate_limit_error',
            'error_code' => 'RATE_LIMIT_EXCEEDED',
            'error_description' => 'API rate limit has been exceeded',
            'api_response' => $token_data,
            'rate_limit_info' => [
                'provider' => 'Amadeus Enterprise',
                'documentation' => 'https://developers.amadeus.com/get-started/rate-limits',
                'retry_after' => $curl_info['retry_after'] ?? 'Unknown'
            ],
            'troubleshooting' => [
                'Wait before making another request',
                'Implement proper rate limiting in your application',
                'Consider upgrading your API subscription',
                'Use caching to reduce API calls'
            ]
        ];
        $response['debug']['validation_steps'][] = 'Rate limit exceeded';

        } else {
            // Other errors
            $response['message'] = "Unexpected API response - HTTP {$http_code}";
            $response['data'] = [
                'error_type' => 'unexpected_error',
                'error_code' => 'HTTP_' . $http_code,
                'error_description' => 'Received unexpected response from API',
                'http_details' => [
                    'status_code' => $http_code,
                    'response_headers' => $curl_info,
                    'response_body' => $result
                ],
                'api_response' => $token_data,
                'troubleshooting' => [
                    'Check API documentation for this status code',
                    'Verify request parameters',
                    'Contact support with this error details',
                    'Check if API endpoint has changed'
                ]
            ];
            $response['debug']['validation_steps'][] = "Unexpected response - HTTP {$http_code}";
        }

    } catch (Exception $e) {
        $response['message'] = 'Internal validation error occurred';
        $response['data'] = [
            'error_type' => 'system_error',
            'error_code' => 'EXCEPTION',
            'error_description' => $e->getMessage(),
            'exception_details' => [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ],
            'troubleshooting' => [
                'Contact system administrator',
                'Check server logs for details',
                'Verify PHP configuration',
                'Ensure all required extensions are installed'
            ]
        ];
        $response['debug']['validation_steps'][] = 'Exception occurred: ' . $e->getMessage();
    }

    // Calculate response time
    $end_time = microtime(true);
    $response['metadata']['response_time_ms'] = round(($end_time - $start_time) * 1000, 2);

    // Return JSON response
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;

    // Helper function for HTTP status texts
    function getHttpStatusText($code) {
        $status_codes = [
            200 => 'OK',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout'
        ];
        return $status_codes[$code] ?? 'Unknown Status';
    }

});
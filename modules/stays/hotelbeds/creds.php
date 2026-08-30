<?php

global $router;

$router->post('stays/hotelbeds/creds', function() {

function processCredentials() {
    // Start timing for performance metrics
    $start_time = microtime(true);

    // Initialize response data
    $response = [
        'success' => false,
        'message' => '',
        'data' => null,
        'metadata' => [
            'module' => 'hotelbeds',
            'service' => 'hotels',
            'provider' => 'Hotelbeds REST API',
            'api_version' => '1.0',
            'timestamp' => date('c'),
            'response_time_ms' => 0,
            'environment' => 'production'
        ],
        'debug' => [
            'validation_steps' => [],
            'api_request' => null,
            'api_response' => null,
            'endpoint_used' => null,
            'request_method' => 'GET'
        ]
    ];

    try {
        $response['debug']['validation_steps'][] = '[START] Starting Hotelbeds REST API credential validation';

        // Get credentials from POST data
        $api_key = trim($_POST['c1'] ?? '');      // API Key
        $secret = trim($_POST['c2'] ?? '');       // Secret
        $environment = trim($_POST['env'] ?? 'production');

        // Update environment in metadata
        $response['metadata']['environment'] = $environment;

        $response['debug']['validation_steps'][] = '[INFO] Parsing submitted credentials';

        // Determine which environment to use
        if ($environment === 'test') {
            $base_endpoint = 'https://api.test.hotelbeds.com/hotel-api/1.0';
            $response['debug']['validation_steps'][] = "[ENV] Environment: TEST/DEVELOPMENT";
        } else {
            $base_endpoint = 'https://api.hotelbeds.com/hotel-api/1.0';
            $response['debug']['validation_steps'][] = "[ENV] Environment: PRODUCTION";
        }

        // Validate required credentials
        if (empty($api_key) || empty($secret)) {
            $response['debug']['validation_steps'][] = '[ERROR] Credential validation failed - missing required fields';

            $missing_fields = [];
            if (empty($api_key)) $missing_fields[] = 'API Key (c1)';
            if (empty($secret)) $missing_fields[] = 'Secret (c2)';

            throw new Exception('Missing required credentials: ' . implode(', ', $missing_fields));
        }

        // Check if using test values
        if ($api_key === 'test' || $secret === 'test') {
            $response['debug']['validation_steps'][] = '[WARNING] Test credentials detected';
            $response['success'] = false;
            $response['message'] = 'Test credentials provided - cannot validate with Hotelbeds';
            $response['data'] = [
                'error_type' => 'test_credentials',
                'error_code' => 'test_values_not_allowed',
                'error_description' => 'Cannot validate with test credentials. Please use your actual Hotelbeds credentials.',
                'provider_details' => [
                    'provider' => 'Hotelbeds REST API',
                    'environment' => $environment,
                    'documentation' => 'https://developer.hotelbeds.com/'
                ],
                'expected_format' => [
                    'c1' => 'Your Hotelbeds API Key',
                    'c2' => 'Your Hotelbeds Secret',
                    'example_c1' => '531c46fc346c6729b9e9094f65abef70',
                    'example_c2' => '1e83c000f3'
                ],
                'troubleshooting' => [
                    'Replace "test" values with your real Hotelbeds credentials',
                    'Get your credentials from Hotelbeds Developer Portal',
                    'Ensure both API Key and Secret are active',
                    'Contact Hotelbeds support if you need new credentials'
                ]
            ];

            // Calculate response time
            $end_time = microtime(true);
            $response['metadata']['response_time_ms'] = round(($end_time - $start_time) * 1000, 2);
            $response['debug']['validation_steps'][] = "[TIME] Response time: {$response['metadata']['response_time_ms']}ms";

            http_response_code(400);
            echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return;
        }

        $response['debug']['validation_steps'][] = '[OK] Required credentials provided';
        $response['debug']['validation_steps'][] = '[KEY] API Key: ' . substr($api_key, 0, 10) . '...' . substr($api_key, -4);
        $response['debug']['validation_steps'][] = '[KEY] Secret: ' . str_repeat('*', strlen($secret));

        // Generate X-Signature
        $timestamp = time();
        $signature_string = $api_key . $secret . $timestamp;
        $signature = hash('sha256', $signature_string);

        $response['debug']['validation_steps'][] = '[KEY] Timestamp: ' . $timestamp;
        $response['debug']['validation_steps'][] = '[KEY] X-Signature generated: ' . substr($signature, 0, 16) . '...';

        $test_endpoint = $base_endpoint . '/status';

        $response['debug']['endpoint_used'] = $test_endpoint;
        $response['debug']['validation_steps'][] = '[API] Test endpoint: ' . $test_endpoint;
        $response['debug']['validation_steps'][] = '[CONNECT] Establishing connection to Hotelbeds API...';

        // Test API with status endpoint (lightweight validation)
        $response['debug']['validation_steps'][] = '[REQUEST] Testing API with /status endpoint...';
        $response['debug']['validation_steps'][] = '[TEST] GET /status (API Health Check)';

        // Initialize cURL
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $test_endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Api-key: ' . $api_key,
                'X-Signature: ' . $signature,
                'User-Agent: PHPTravels-v10/1.0'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true
        ]);

        // Execute the request
        $api_response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $connect_time = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        $total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);


        $response['debug']['validation_steps'][] = "[RESPONSE] API Response: HTTP {$http_code} (connect: {$connect_time}s, total: {$total_time}s)";

        if ($curl_error) {
            $response['debug']['validation_steps'][] = '[ERROR] Network error occurred';
            throw new Exception('Network Error: ' . $curl_error);
        }

        // Store response for debugging (first 1000 chars)
        $response['debug']['api_response'] = substr($api_response, 0, 1000) . (strlen($api_response) > 1000 ? '...' : '');

        // Parse JSON response
        if ($http_code === 200) {
            $api_data = json_decode($api_response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $response['debug']['validation_steps'][] = '[ERROR] Failed to parse JSON response';
                throw new Exception('Invalid JSON response from Hotelbeds API');
            }

            // Check for successful status response
            $response['debug']['validation_steps'][] = '[SUCCESS] Authentication successful! API returned valid status';
            $response['debug']['validation_steps'][] = '[DATA] API is operational and credentials are valid';

            // Success response
            $response['success'] = true;
            $response['message'] = 'Hotelbeds REST API credentials validated successfully';
            $response['data'] = [
                'connection_status' => 'connected',
                'authentication' => [
                    'status' => 'authenticated',
                    'auth_type' => 'REST API (X-Signature SHA256)',
                    'environment' => $environment,
                    'api_key' => substr($api_key, 0, 10) . '...' . substr($api_key, -4)
                ],
                'api_details' => [
                    'provider' => 'Hotelbeds',
                    'api_version' => '1.0',
                    'api_type' => 'REST API',
                    'endpoint' => $base_endpoint,
                    'response_time' => round($total_time * 1000, 2) . 'ms',
                    'supported_services' => [
                        'Hotel Search',
                        'Hotel Details',
                        'Availability & Rates',
                        'Booking Creation',
                        'Booking Management',
                        'Cancellation',
                        'Rate Check'
                    ]
                ],
                'test_result' => [
                    'test_type' => 'status_check',
                    'test_endpoint' => '/status',
                    'api_responsive' => true,
                    'credentials_valid' => true,
                    'connection_time' => round($connect_time * 1000, 2) . 'ms',
                    'status_data' => $api_data
                ]
            ];

        } else {
            $response['debug']['validation_steps'][] = "[ERROR] Authentication failed with HTTP {$http_code}";

            // Try to parse error response
            $error_data = json_decode($api_response, true);

            $error_details = [
                'error_type' => 'authentication_error',
                'error_code' => 'http_' . $http_code,
                'error_description' => "HTTP {$http_code} error from Hotelbeds API",
                'http_details' => [
                    'status_code' => $http_code,
                    'endpoint_used' => $test_endpoint,
                    'connection_time' => round($connect_time * 1000, 2) . 'ms',
                    'total_time' => round($total_time * 1000, 2) . 'ms'
                ]
            ];

            // Add API error details if available
            if ($error_data && isset($error_data['error'])) {
                $error_details['api_error'] = $error_data['error'];
            }

            // Add specific troubleshooting based on HTTP status
            switch ($http_code) {
                case 400:
                    $error_details['issue'] = 'Bad Request - Invalid signature or malformed request';
                    $error_details['solutions'] = [
                        'Check if your API Key is correct',
                        'Verify the Secret is correct',
                        'Ensure timestamp is current',
                        'Check signature generation logic'
                    ];
                    break;
                case 401:
                case 403:
                    $error_details['issue'] = 'Unauthorized - Invalid credentials or signature';
                    $error_details['solutions'] = [
                        'Verify your API Key is correct and active',
                        'Check if your Secret is correct',
                        'Ensure credentials match your Hotelbeds account',
                        'Verify X-Signature is calculated correctly (SHA256 of ApiKey+Secret+Timestamp)',
                        'Contact Hotelbeds support for credential verification'
                    ];
                    break;
                case 429:
                    $error_details['issue'] = 'Rate Limit Exceeded';
                    $error_details['solutions'] = [
                        'You have exceeded the API rate limit',
                        'Wait a few minutes before trying again',
                        'Check your API usage limits',
                        'Contact Hotelbeds to increase your rate limits'
                    ];
                    break;
                case 500:
                case 502:
                case 503:
                    $error_details['issue'] = 'Server Error - Hotelbeds API issue';
                    $error_details['solutions'] = [
                        'This is a temporary server error',
                        'Wait a few minutes and try again',
                        'Check Hotelbeds API status',
                        'Try again later if issue persists'
                    ];
                    break;
                default:
                    $error_details['issue'] = 'Unexpected HTTP response';
                    $error_details['solutions'] = [
                        'Check your network connectivity',
                        'Verify both API Key and Secret are correct',
                        'Ensure signature is calculated correctly',
                        'Try again in a few minutes',
                        'Contact Hotelbeds support'
                    ];
            }

            $response['data'] = $error_details;
            $response['message'] = "Authentication failed - HTTP {$http_code}";
            throw new Exception("HTTP {$http_code} error from Hotelbeds API");
        }

    } catch (Exception $e) {
        $response['debug']['validation_steps'][] = '[ERROR] ' . $e->getMessage();

        if (!isset($response['data']) || $response['data'] === null) {
            $response['data'] = [
                'error_type' => 'validation_error',
                'error_code' => 'credential_validation_failed',
                'error_description' => $e->getMessage(),
                'quick_fixes' => [
                    'Ensure you have valid Hotelbeds credentials',
                    'Check both API Key and Secret are correct',
                    'Verify signature calculation (SHA256 of ApiKey+Secret+Timestamp)',
                    'Verify network connectivity to Hotelbeds servers',
                    'Get credentials from Hotelbeds Developer Portal',
                    'Contact Hotelbeds support if issues persist'
                ]
            ];
        }

        if (empty($response['message'])) {
            $response['message'] = 'Hotelbeds REST API credential validation failed';
        }
    }

    // Calculate final response time
    $end_time = microtime(true);
    $response['metadata']['response_time_ms'] = round(($end_time - $start_time) * 1000, 2);
    $response['debug']['validation_steps'][] = "[TIME] Total processing time: {$response['metadata']['response_time_ms']}ms";

    // Set appropriate HTTP status code
    http_response_code($response['success'] ? 200 : 400);

    // Return optimized JSON response
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} // End of processCredentials function

    processCredentials();

});

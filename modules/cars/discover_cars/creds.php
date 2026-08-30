<?php

// Set proper headers for JSON response
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, must-revalidate');

global $router;

$router->post('cars/discover_cars/creds', function() {

function processCredentials() {
    // Start timing for performance metrics
    $start_time = microtime(true);

    // Initialize response data
    $response = [
        'success' => false,
        'message' => '',
        'data' => null,
        'metadata' => [
            'module' => 'discover_cars',
            'service' => 'cars',
            'provider' => 'Discover Cars API',
            'api_version' => 'v1',
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
        $response['debug']['validation_steps'][] = '[START] Starting Discover Cars API credential validation';

        // Get credentials from POST data
        $username = trim($_POST['c1'] ?? '');     // Username
        $password = trim($_POST['c2'] ?? '');     // Password
        $token = trim($_POST['c3'] ?? '');        // API Token
        $environment = trim($_POST['env'] ?? 'production');

        // Update environment in metadata
        $response['metadata']['environment'] = $environment;

        $response['debug']['validation_steps'][] = '[INFO] Parsing submitted credentials';

        // Discover Cars uses production endpoint only
        $base_endpoint = 'https://api-partner.discovercars.com';
        $response['debug']['validation_steps'][] = "[ENV] Environment: PRODUCTION (Discover Cars has only production)";

        if ($environment === 'test') {
            $response['debug']['validation_steps'][] = "[INFO] Note: Using production endpoint (no separate test environment)";
        }

        // Validate required credentials
        if (empty($username) || empty($password) || empty($token)) {
            $response['debug']['validation_steps'][] = '[ERROR] Credential validation failed - missing required fields';

            $missing_fields = [];
            if (empty($username)) $missing_fields[] = 'Username (c1)';
            if (empty($password)) $missing_fields[] = 'Password (c2)';
            if (empty($token)) $missing_fields[] = 'Token (c3)';

            throw new Exception('Missing required credentials: ' . implode(', ', $missing_fields));
        }

        // Check if using test values
        if ($username === 'test' || $password === 'test' || $token === 'test') {
            $response['debug']['validation_steps'][] = '[WARNING] Test credentials detected';
            $response['success'] = false;
            $response['message'] = 'Test credentials provided - cannot validate with Discover Cars';
            $response['data'] = [
                'error_type' => 'test_credentials',
                'error_code' => 'test_values_not_allowed',
                'error_description' => 'Cannot validate with test credentials. Please use your actual Discover Cars credentials.',
                'provider_details' => [
                    'provider' => 'Discover Cars API',
                    'environment' => $environment,
                    'documentation' => 'https://www.discovercars.com/api-documentation'
                ],
                'expected_format' => [
                    'c1' => 'Your Discover Cars Username',
                    'c2' => 'Your Discover Cars Password',
                    'c3' => 'Your Discover Cars Token',
                    'example_c1' => 'your_username',
                    'example_c2' => 'your_password',
                    'example_c3' => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
                ],
                'troubleshooting' => [
                    'Replace "test" values with your real Discover Cars credentials',
                    'Get your credentials from Discover Cars Partner Portal',
                    'Ensure all three credentials (username, password, token) are active',
                    'Contact Discover Cars support if you need new credentials'
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
        $response['debug']['validation_steps'][] = '[KEY] Username: ' . $username;
        $response['debug']['validation_steps'][] = '[KEY] Password: ' . str_repeat('*', strlen($password)) . ' (length: ' . strlen($password) . ')';
        $response['debug']['validation_steps'][] = '[KEY] Token: ' . substr($token, 0, 8) . '************************ (length: ' . strlen($token) . ')';

        $response['debug']['endpoint_used'] = $base_endpoint;
        $response['debug']['validation_steps'][] = '[API] Base endpoint: ' . $base_endpoint;
        $response['debug']['validation_steps'][] = '[CONNECT] Establishing connection to Discover Cars API...';

        // Test API with Currencies endpoint (lightweight, reliable endpoint for testing)
        // Using Basic Auth + access_token
        $test_endpoint = $base_endpoint . '/api/Aggregator/Currencies?access_token=' . urlencode($token);

        $response['debug']['validation_steps'][] = '[REQUEST] Testing API with Currencies endpoint...';
        $response['debug']['validation_steps'][] = '[TEST] GET /api/Aggregator/Currencies';

        // Create Basic Auth header
        $auth_string = base64_encode($username . ':' . $password);

        // Browser-like User-Agent required to bypass Cloudflare
        $browserUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

        // Initialize cURL
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $test_endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . $auth_string,
                'Accept: application/json',
                'User-Agent: ' . $browserUA,
                'Accept-Encoding: gzip, deflate, br'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING => ''
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

        // Try to parse JSON response
        $api_data = json_decode($api_response, true);

        // Parse API response
        if ($http_code === 200) {
            // Check for valid response - Currencies endpoint returns array of currency objects
            if (is_array($api_data) && !empty($api_data) && (isset($api_data[0]['Code']) || isset($api_data[0]['Name']))) {
                $response['debug']['validation_steps'][] = '[SUCCESS] Authentication successful! API returned currencies data';

                $currencies_count = count($api_data);

                $response['debug']['validation_steps'][] = '[DATA] Total currencies: ' . $currencies_count;
                $response['debug']['validation_steps'][] = '[DATA] API Version: v1';

                // Success response
                $response['success'] = true;
                $response['message'] = 'Discover Cars API credentials validated successfully';
                $response['data'] = [
                    'connection_status' => 'connected',
                    'authentication' => [
                        'status' => 'authenticated',
                        'auth_type' => 'Basic Auth + Access Token',
                        'environment' => 'production',
                        'username' => $username,
                        'token_preview' => substr($token, 0, 8) . '...'
                    ],
                    'api_details' => [
                        'provider' => 'Discover Cars',
                        'api_version' => 'v1',
                        'api_title' => 'DCH.PartnerLab',
                        'endpoint' => $base_endpoint,
                        'response_time' => round($total_time * 1000, 2) . 'ms',
                        'currencies_available' => $currencies_count,
                        'supported_services' => [
                            'Car Search',
                            'Location Data',
                            'Availability Check',
                            'Pricing & Rates',
                            'Booking Management',
                            'Supplier Information',
                            'Vehicle Categories'
                        ]
                    ],
                    'test_result' => [
                        'test_type' => 'currencies_endpoint',
                        'api_accessible' => true,
                        'api_responsive' => true,
                        'credentials_valid' => true,
                        'connection_time' => round($connect_time * 1000, 2) . 'ms'
                    ]
                ];

            } else {
                $response['debug']['validation_steps'][] = '[WARNING] API responded but data format unexpected';
                throw new Exception('API responded but returned unexpected data format');
            }

        } else {
            $response['debug']['validation_steps'][] = "[ERROR] Authentication failed with HTTP {$http_code}";

            // Try to extract error from JSON response
            $error_message = 'Unknown error';
            $error_code = 'http_' . $http_code;

            if ($api_data) {
                if (isset($api_data['error'])) {
                    $error_message = $api_data['error'];
                } elseif (isset($api_data['message'])) {
                    $error_message = $api_data['message'];
                } elseif (isset($api_data['detail'])) {
                    $error_message = $api_data['detail'];
                }
                $response['debug']['validation_steps'][] = '[ERROR] API Error: ' . $error_message;
            }

            $error_details = [
                'error_type' => 'authentication_error',
                'error_code' => $error_code,
                'error_description' => "HTTP {$http_code} error from Discover Cars API: {$error_message}",
                'http_details' => [
                    'status_code' => $http_code,
                    'endpoint_used' => $test_endpoint,
                    'connection_time' => round($connect_time * 1000, 2) . 'ms',
                    'total_time' => round($total_time * 1000, 2) . 'ms'
                ]
            ];

            // Add API error details if available
            if (isset($api_data) && $api_data) {
                $error_details['api_error'] = $api_data;
                $response['debug']['validation_steps'][] = '[INFO] API error details added to error response';
            }

            // Add specific troubleshooting based on HTTP status
            switch ($http_code) {
                case 400:
                    $error_details['issue'] = 'Bad Request - Invalid API request';
                    $error_details['solutions'] = [
                        'Check if all credentials are correct',
                        'Verify the token format is valid',
                        'Ensure there are no extra spaces in credentials',
                        'Verify username and password are correct'
                    ];
                    break;
                case 401:
                    $error_details['issue'] = 'Unauthorized - Invalid credentials';
                    $error_details['solutions'] = [
                        'Verify your username and password are correct',
                        'Check if your API Token is correct and active',
                        'Ensure all three credentials match your account',
                        'Try regenerating your API Token',
                        'Contact Discover Cars support for credential verification'
                    ];
                    break;
                case 403:
                    $error_details['issue'] = 'Forbidden - API access denied';
                    $error_details['solutions'] = [
                        'Your account may not have API access enabled',
                        'Contact Discover Cars to enable API access',
                        'Verify your account is in good standing',
                        'Check if there are any usage restrictions on your account'
                    ];
                    break;
                case 404:
                    $error_details['issue'] = 'Not Found - Endpoint not found';
                    $error_details['solutions'] = [
                        'The API endpoint may have changed',
                        'Verify you are using the correct environment',
                        'Check Discover Cars API documentation for updates',
                        'Contact Discover Cars support if issue persists'
                    ];
                    break;
                case 429:
                    $error_details['issue'] = 'Rate Limit Exceeded';
                    $error_details['solutions'] = [
                        'You have exceeded API rate limits',
                        'Wait a few minutes and try again',
                        'Consider optimizing your API requests',
                        'Contact Discover Cars about rate limit increases'
                    ];
                    break;
                case 500:
                case 502:
                case 503:
                    $error_details['issue'] = 'Server Error - Discover Cars API issue';
                    $error_details['solutions'] = [
                        'This is a temporary server error',
                        'Wait a few minutes and try again',
                        'Check Discover Cars API status',
                        'Try again later if issue persists'
                    ];
                    break;
                default:
                    $error_details['issue'] = 'Unexpected HTTP response';
                    $error_details['solutions'] = [
                        'Check your network connectivity',
                        'Verify all three credentials are correct',
                        'Try again in a few minutes',
                        'Check Discover Cars API documentation'
                    ];
            }

            $response['data'] = $error_details;
            $response['message'] = "Authentication failed - HTTP {$http_code}";
            throw new Exception("HTTP {$http_code} error from Discover Cars API");
        }

    } catch (Exception $e) {
        $response['debug']['validation_steps'][] = '[ERROR] ' . $e->getMessage();

        if (!isset($response['data']) || $response['data'] === null) {
            $response['data'] = [
                'error_type' => 'validation_error',
                'error_code' => 'credential_validation_failed',
                'error_description' => $e->getMessage(),
                'quick_fixes' => [
                    'Ensure you have valid Discover Cars credentials',
                    'Check username, password, and token are all correct',
                    'Verify network connectivity to Discover Cars servers',
                    'Get credentials from Discover Cars Partner Portal',
                    'Contact Discover Cars support if issues persist'
                ]
            ];
        }

        if (empty($response['message'])) {
            $response['message'] = 'Discover Cars API credential validation failed';
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
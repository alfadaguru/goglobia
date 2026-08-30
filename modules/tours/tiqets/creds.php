<?php

global $router;

$router->post('tours/tiqets/creds', function() {

function processCredentials() {
    // Start timing for performance metrics
    $start_time = microtime(true);

    // Initialize response data
    $response = [
        'success' => false,
        'message' => '',
        'data' => null,
        'metadata' => [
            'module' => 'tiqets',
            'service' => 'tours',
            'provider' => 'Tiqets API',
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
        $response['debug']['validation_steps'][] = '[START] Starting Tiqets API credential validation';

        // Get credentials from POST data
        $api_key = trim($_POST['c1'] ?? '');      // API Token
        $environment = trim($_POST['env'] ?? 'production');

        // Update environment in metadata
        $response['metadata']['environment'] = $environment;

        $response['debug']['validation_steps'][] = '[INFO] Parsing submitted credentials';

        // Tiqets API endpoint (production only)
        $base_endpoint = 'https://api.tiqets.com';
        $response['debug']['validation_steps'][] = "[ENV] Environment: PRODUCTION (Tiqets has only production)";

        // Validate required credentials
        if (empty($api_key)) {
            $response['debug']['validation_steps'][] = '[ERROR] Credential validation failed - missing API Key';
            throw new Exception('Missing required credentials: API Key (c1)');
        }

        // Check if using test values
        if ($api_key === 'test') {
            $response['debug']['validation_steps'][] = '[WARNING] Test credentials detected';
            $response['success'] = false;
            $response['message'] = 'Test credentials provided - cannot validate with Tiqets';
            $response['data'] = [
                'error_type' => 'test_credentials',
                'error_code' => 'test_values_not_allowed',
                'error_description' => 'Cannot validate with test credentials. Please use your actual Tiqets API token.',
                'provider_details' => [
                    'provider' => 'Tiqets API',
                    'environment' => 'production',
                    'documentation' => 'https://developers.tiqets.dev/'
                ],
                'expected_format' => [
                    'c1' => 'Your Tiqets API Token',
                    'example_c1' => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
                ],
                'troubleshooting' => [
                    'Replace "test" value with your real Tiqets API Token',
                    'Get your API Token from Tiqets Developer Portal',
                    'Ensure your API Token is active',
                    'Visit https://developers.tiqets.dev/ for documentation',
                    'Contact Tiqets support if you need new credentials'
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
        $response['debug']['validation_steps'][] = '[KEY] API Token: ' . substr($api_key, 0, 8) . '************************ (length: ' . strlen($api_key) . ')';

        // Validate API Key format
        if (strlen($api_key) < 20) {
            $response['debug']['validation_steps'][] = '[WARNING] API Token seems too short';
        } else {
            $response['debug']['validation_steps'][] = '[OK] API Token format looks valid';
        }

        $response['debug']['endpoint_used'] = $base_endpoint;
        $response['debug']['validation_steps'][] = '[API] Base endpoint: ' . $base_endpoint;
        $response['debug']['validation_steps'][] = '[CONNECT] Establishing connection to Tiqets API...';

        // Test API with venues endpoint (lightweight endpoint for testing)
        $test_endpoint = $base_endpoint . '/v2/venues?page_size=1';

        $response['debug']['validation_steps'][] = '[REQUEST] Testing API with venues endpoint...';
        $response['debug']['validation_steps'][] = '[TEST] GET /v2/venues?page_size=1';

        // Initialize cURL
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $test_endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Token ' . $api_key,
                'Accept: application/json',
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

        // Try to parse JSON response
        $api_data = json_decode($api_response, true);

        // Parse API response
        if ($http_code === 200) {
            // Check for valid response with venues data
            if (isset($api_data['venues']) && is_array($api_data['venues'])) {
                $response['debug']['validation_steps'][] = '[SUCCESS] Authentication successful! API returned valid data';
                $response['debug']['validation_steps'][] = '[DATA] Found ' . count($api_data['venues']) . ' venue(s) in test response';

                // Get pagination info if available
                $total_venues = isset($api_data['pagination']['total']) ? $api_data['pagination']['total'] : count($api_data['venues']);

                // Success response
                $response['success'] = true;
                $response['message'] = 'Tiqets API credentials validated successfully';
                $response['data'] = [
                    'connection_status' => 'connected',
                    'authentication' => [
                        'status' => 'authenticated',
                        'auth_type' => 'Token Authentication',
                        'environment' => 'production',
                        'api_token_preview' => substr($api_key, 0, 8) . '...'
                    ],
                    'api_details' => [
                        'provider' => 'Tiqets',
                        'api_version' => 'v2',
                        'endpoint' => $base_endpoint,
                        'response_time' => round($total_time * 1000, 2) . 'ms',
                        'supported_services' => [
                            'Venue Search',
                            'Product Search',
                            'Product Details',
                            'Availability Check',
                            'Booking Management',
                            'City & Location Data',
                            'Reviews & Ratings'
                        ]
                    ],
                    'test_result' => [
                        'test_type' => 'venues_query',
                        'venues_returned' => count($api_data['venues']),
                        'total_venues' => $total_venues,
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
                'error_description' => "HTTP {$http_code} error from Tiqets API: {$error_message}",
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
                        'Check if your API Token is correct',
                        'Verify the token format is valid',
                        'Ensure there are no extra spaces in the token',
                        'Try regenerating your API Token from Tiqets portal'
                    ];
                    break;
                case 401:
                    $error_details['issue'] = 'Unauthorized - Invalid API Token';
                    $error_details['solutions'] = [
                        'Verify your API Token is correct and active',
                        'Check if your API Token has expired',
                        'Ensure you copied the complete token',
                        'Generate a new API Token from https://developers.tiqets.dev/',
                        'Contact Tiqets support for API access verification'
                    ];
                    break;
                case 403:
                    $error_details['issue'] = 'Forbidden - API access denied';
                    $error_details['solutions'] = [
                        'Your API Token may not have sufficient permissions',
                        'Contact Tiqets to enable full API access',
                        'Verify your account is in good standing',
                        'Check if there are any usage restrictions on your account'
                    ];
                    break;
                case 404:
                    $error_details['issue'] = 'Not Found - Endpoint not found';
                    $error_details['solutions'] = [
                        'The API endpoint may have changed',
                        'Verify you are using the correct API version (v2)',
                        'Check Tiqets API documentation for updates',
                        'Contact Tiqets support if issue persists'
                    ];
                    break;
                case 429:
                    $error_details['issue'] = 'Rate Limit Exceeded';
                    $error_details['solutions'] = [
                        'You have exceeded API rate limits',
                        'Wait a few minutes and try again',
                        'Consider optimizing your API requests',
                        'Contact Tiqets about rate limit increases'
                    ];
                    break;
                case 500:
                case 502:
                case 503:
                    $error_details['issue'] = 'Server Error - Tiqets API issue';
                    $error_details['solutions'] = [
                        'This is a temporary server error',
                        'Wait a few minutes and try again',
                        'Check Tiqets API status',
                        'Try again later if issue persists'
                    ];
                    break;
                default:
                    $error_details['issue'] = 'Unexpected HTTP response';
                    $error_details['solutions'] = [
                        'Check your network connectivity',
                        'Verify your API Token is correct',
                        'Try again in a few minutes',
                        'Check Tiqets API documentation at https://developers.tiqets.dev/'
                    ];
            }

            $response['data'] = $error_details;
            $response['message'] = "Authentication failed - HTTP {$http_code}";
            throw new Exception("HTTP {$http_code} error from Tiqets API");
        }

    } catch (Exception $e) {
        $response['debug']['validation_steps'][] = '[ERROR] ' . $e->getMessage();

        if (!isset($response['data']) || $response['data'] === null) {
            $response['data'] = [
                'error_type' => 'validation_error',
                'error_code' => 'credential_validation_failed',
                'error_description' => $e->getMessage(),
                'quick_fixes' => [
                    'Ensure you have a valid Tiqets API Token',
                    'Check your API Token is active and not expired',
                    'Verify network connectivity to Tiqets servers',
                    'Get API Token from https://developers.tiqets.dev/',
                    'Contact Tiqets support if issues persist'
                ]
            ];
        }

        if (empty($response['message'])) {
            $response['message'] = 'Tiqets API credential validation failed';
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

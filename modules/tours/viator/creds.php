<?php

global $router;

$router->post('tours/viator/creds', function() {

function processCredentials() {
    // Start timing for performance metrics
    $start_time = microtime(true);

    // Initialize response data
    $response = [
        'success' => false,
        'message' => '',
        'data' => null,
        'metadata' => [
            'module' => 'viator',
            'service' => 'tours',
            'provider' => 'Viator API',
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
        $response['debug']['validation_steps'][] = '[START] Starting Viator API credential validation';

        // Get credentials from POST data
        $api_key = trim($_POST['c1'] ?? '');      // API Key
        $environment = trim($_POST['env'] ?? 'production');

        // Update environment in metadata
        $response['metadata']['environment'] = $environment;

        $response['debug']['validation_steps'][] = '[INFO] Parsing submitted credentials';

        // Determine which environment to use
        if ($environment === 'test') {
            $base_endpoint = 'https://api.sandbox.viator.com';
            $response['debug']['validation_steps'][] = "[ENV] Environment: TEST/SANDBOX";
        } else {
            $base_endpoint = 'https://api.viator.com';
            $response['debug']['validation_steps'][] = "[ENV] Environment: PRODUCTION";
        }

        // Validate required credentials
        if (empty($api_key)) {
            $response['debug']['validation_steps'][] = '[ERROR] Credential validation failed - missing API Key';
            throw new Exception('Missing required credentials: API Key (c1)');
        }

        // Check if using test values
        if ($api_key === 'test') {
            $response['debug']['validation_steps'][] = '[WARNING] Test credentials detected';
            $response['success'] = false;
            $response['message'] = 'Test credentials provided - cannot validate with Viator';
            $response['data'] = [
                'error_type' => 'test_credentials',
                'error_code' => 'test_values_not_allowed',
                'error_description' => 'Cannot validate with test credentials. Please use your actual Viator API key.',
                'provider_details' => [
                    'provider' => 'Viator API',
                    'environment' => $environment,
                    'documentation' => 'https://docs.viator.com/'
                ],
                'expected_format' => [
                    'c1' => 'Your Viator API Key',
                    'example_c1' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'
                ],
                'troubleshooting' => [
                    'Replace "test" value with your real Viator API Key',
                    'Get your API Key from Viator Partner Portal',
                    'Ensure your API Key is active',
                    'Contact Viator support if you need new credentials'
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
        $response['debug']['validation_steps'][] = '[KEY] API Key: ' . substr($api_key, 0, 8) . '************************ (length: ' . strlen($api_key) . ')';

        // Validate API Key format (typically UUID format)
        if (strlen($api_key) < 30) {
            $response['debug']['validation_steps'][] = '[WARNING] API Key seems too short (expected ~36 characters)';
        } else {
            $response['debug']['validation_steps'][] = '[OK] API Key format looks valid';
        }

        $response['debug']['endpoint_used'] = $base_endpoint;
        $response['debug']['validation_steps'][] = '[API] Base endpoint: ' . $base_endpoint;
        $response['debug']['validation_steps'][] = '[CONNECT] Establishing connection to Viator API...';

        // Test API with a simple search endpoint (matches working code)
        $test_endpoint = $base_endpoint . '/partner/search/freetext';

        $response['debug']['validation_steps'][] = '[REQUEST] Testing API with search endpoint...';
        $response['debug']['validation_steps'][] = '[TEST] POST /partner/search/freetext';

        // Minimal test search payload
        $test_data = json_encode([
            'searchTerm' => 'Paris',
            'searchTypes' => [[
                'searchType' => 'PRODUCTS',
                'pagination' => [
                    'start' => 1,
                    'count' => 1
                ]
            ]],
            'currency' => 'USD'
        ]);

        // Initialize cURL
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $test_endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $test_data,
            CURLOPT_HTTPHEADER => [
                'exp-api-key: ' . $api_key,
                'Accept: application/json;version=2.0',
                'Accept-Language: en-US',
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false
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
            // Check for valid response with products or data
            $api_data = json_decode($api_response, true);
            
            if ((isset($api_data['products']) && is_array($api_data['products'])) || 
                (isset($api_data['data']) && is_array($api_data['data']))) {
                
                $response['debug']['validation_steps'][] = '[SUCCESS] Authentication successful! API returned valid data';
                
                if (isset($api_data['products']['results'])) {
                    $response['debug']['validation_steps'][] = '[DATA] Found ' . count($api_data['products']['results']) . ' product(s)';
                }

                // Success response
                $response['success'] = true;
                $response['message'] = 'Viator API credentials validated successfully';
                $response['data'] = [
                    'connection_status' => 'connected',
                    'authentication' => [
                        'status' => 'authenticated',
                        'auth_type' => 'API Key',
                        'environment' => $environment,
                        'api_key_preview' => substr($api_key, 0, 8) . '...'
                    ],
                    'api_details' => [
                        'provider' => 'Viator API',
                        'api_version' => 'v2.0',
                        'endpoint' => $base_endpoint,
                        'response_time' => round($total_time * 1000, 2) . 'ms',
                        'supported_services' => [
                            'Product Search',
                            'Product Details',
                            'Availability & Pricing',
                            'Booking Management',
                            'Reviews & Ratings',
                            'Cancellation Policies'
                        ]
                    ],
                    'test_result' => [
                        'test_type' => 'search_freetext',
                        'test_query' => 'Paris',
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
                } elseif (isset($api_data['errorMessage'])) {
                    $error_message = $api_data['errorMessage'];
                }
                $response['debug']['validation_steps'][] = '[ERROR] API Error: ' . $error_message;
            }

            $error_details = [
                'error_type' => 'authentication_error',
                'error_code' => $error_code,
                'error_description' => "HTTP {$http_code} error from Viator API: {$error_message}",
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
                        'Check if your API Key is correct',
                        'Verify the API Key format',
                        'Ensure there are no extra spaces in credentials',
                        'Check the endpoint URL is correct for your environment'
                    ];
                    break;
                case 401:
                    $error_details['issue'] = 'Unauthorized - Invalid API Key';
                    $error_details['solutions'] = [
                        'Verify your API Key is correct and active',
                        'Check if your API Key has expired',
                        'Ensure you copied the complete API Key',
                        'Login to Viator Partner Portal to verify/regenerate key',
                        'Contact Viator support for API access verification'
                    ];
                    break;
                case 403:
                    $error_details['issue'] = 'Forbidden - API access denied';
                    $error_details['solutions'] = [
                        'Your API Key may not have sufficient permissions',
                        'Contact Viator to enable full API access',
                        'Verify your partner account is in good standing',
                        'Check if there are any usage restrictions on your account'
                    ];
                    break;
                case 429:
                    $error_details['issue'] = 'Rate Limit Exceeded';
                    $error_details['solutions'] = [
                        'You have exceeded API rate limits',
                        'Wait a few minutes and try again',
                        'Consider upgrading your API plan for higher limits',
                        'Contact Viator about rate limit increases'
                    ];
                    break;
                case 500:
                case 502:
                case 503:
                    $error_details['issue'] = 'Server Error - Viator API issue';
                    $error_details['solutions'] = [
                        'This is a temporary server error',
                        'Wait a few minutes and try again',
                        'Check Viator API status page',
                        'Try again later if issue persists'
                    ];
                    break;
                default:
                    $error_details['issue'] = 'Unexpected HTTP response';
                    $error_details['solutions'] = [
                        'Check your network connectivity',
                        'Verify your credentials are correct',
                        'Try again in a few minutes',
                        'Check Viator API documentation'
                    ];
            }

            $response['data'] = $error_details;
            $response['message'] = "Authentication failed - HTTP {$http_code}";
            throw new Exception("HTTP {$http_code} error from Viator API");
        }

    } catch (Exception $e) {
        $response['debug']['validation_steps'][] = '[ERROR] ' . $e->getMessage();

        if (!isset($response['data']) || $response['data'] === null) {
            $response['data'] = [
                'error_type' => 'validation_error',
                'error_code' => 'credential_validation_failed',
                'error_description' => $e->getMessage(),
                'quick_fixes' => [
                    'Ensure you have a valid Viator API Key',
                    'Verify network connectivity to Viator servers',
                    'Get API Key from Viator Partner Portal',
                    'Check if your API Key is active',
                    'Contact Viator support if issues persist'
                ]
            ];
        }

        if (empty($response['message'])) {
            $response['message'] = 'Viator API credential validation failed';
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

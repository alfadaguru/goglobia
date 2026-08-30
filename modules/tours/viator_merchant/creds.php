<?php

global $router;

$router->post('tours/viator_merchant/creds', function() {

function processCredentials() {
    // Start timing for performance metrics
    $start_time = microtime(true);

    // Initialize response data
    $response = [
        'success' => false,
        'message' => '',
        'data' => null,
        'metadata' => [
            'module' => 'viator_merchant',
            'service' => 'tours',
            'provider' => 'Viator Merchant API',
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
        $response['debug']['validation_steps'][] = '[START] Starting Viator Merchant API credential validation';

        // Get credentials from POST data
        $api_key = trim($_POST['c1'] ?? '');         // API Key
        $merchant_id = trim($_POST['c2'] ?? '');     // Merchant ID
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
        if (empty($api_key) || empty($merchant_id)) {
            $response['debug']['validation_steps'][] = '[ERROR] Credential validation failed - missing required fields';

            $missing_fields = [];
            if (empty($api_key)) $missing_fields[] = 'API Key (c1)';
            if (empty($merchant_id)) $missing_fields[] = 'Merchant ID (c2)';

            throw new Exception('Missing required credentials: ' . implode(', ', $missing_fields));
        }

        // Check if using test values
        if ($api_key === 'test' || $merchant_id === 'test') {
            $response['debug']['validation_steps'][] = '[WARNING] Test credentials detected';
            $response['success'] = false;
            $response['message'] = 'Test credentials provided - cannot validate with Viator Merchant API';
            $response['data'] = [
                'error_type' => 'test_credentials',
                'error_code' => 'test_values_not_allowed',
                'error_description' => 'Cannot validate with test credentials. Please use your actual Viator Merchant credentials.',
                'provider_details' => [
                    'provider' => 'Viator Merchant API',
                    'environment' => $environment,
                    'documentation' => 'https://docs.viator.com/'
                ],
                'expected_format' => [
                    'c1' => 'Your Viator Merchant API Key',
                    'c2' => 'Your Merchant ID',
                    'example_c1' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
                    'example_c2' => 'merchant-123456'
                ],
                'troubleshooting' => [
                    'Replace "test" values with your real Viator Merchant credentials',
                    'Get your credentials from Viator Merchant Portal',
                    'Ensure your API Key and Merchant ID are active',
                    'Contact Viator Merchant support if you need new credentials'
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
        $response['debug']['validation_steps'][] = '[KEY] Merchant ID: ' . $merchant_id;

        // Validate API Key format (typically UUID format)
        if (strlen($api_key) < 30) {
            $response['debug']['validation_steps'][] = '[WARNING] API Key seems too short (expected ~36 characters)';
        } else {
            $response['debug']['validation_steps'][] = '[OK] API Key format looks valid';
        }

        $response['debug']['endpoint_used'] = $base_endpoint;
        $response['debug']['validation_steps'][] = '[API] Base endpoint: ' . $base_endpoint;
        $response['debug']['validation_steps'][] = '[CONNECT] Establishing connection to Viator Merchant API...';

        // Test API with merchant/products endpoint
        $test_endpoint = $base_endpoint . '/merchant/v1/products';

        $response['debug']['validation_steps'][] = '[REQUEST] Testing API with merchant products endpoint...';
        $response['debug']['validation_steps'][] = '[TEST] GET /merchant/v1/products';

        // Initialize cURL
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $test_endpoint . '?count=1',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'exp-api-key: ' . $api_key,
                'Accept: application/json',
                'Accept-Language: en-US',
                'User-Agent: PHPTravels-v10/1.0'
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
            // Check for valid response with products data
            if (isset($api_data['products']) && is_array($api_data['products'])) {
                $response['debug']['validation_steps'][] = '[SUCCESS] Authentication successful! API returned valid merchant data';
                $response['debug']['validation_steps'][] = '[DATA] Found ' . count($api_data['products']) . ' product(s) for merchant';

                // Success response
                $response['success'] = true;
                $response['message'] = 'Viator Merchant API credentials validated successfully';
                $response['data'] = [
                    'connection_status' => 'connected',
                    'authentication' => [
                        'status' => 'authenticated',
                        'auth_type' => 'API Key + Merchant ID',
                        'environment' => $environment,
                        'merchant_id' => $merchant_id,
                        'api_key_preview' => substr($api_key, 0, 8) . '...'
                    ],
                    'api_details' => [
                        'provider' => 'Viator Merchant API',
                        'api_version' => 'v1',
                        'endpoint' => $base_endpoint,
                        'response_time' => round($total_time * 1000, 2) . 'ms',
                        'supported_services' => [
                            'Product Management',
                            'Availability Management',
                            'Booking Management',
                            'Pricing & Currency',
                            'Reviews Management',
                            'Merchant Analytics',
                            'Inventory Control'
                        ]
                    ],
                    'test_result' => [
                        'test_type' => 'merchant_products',
                        'products_count' => count($api_data['products']),
                        'merchant_id' => $merchant_id,
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
                'error_description' => "HTTP {$http_code} error from Viator Merchant API: {$error_message}",
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
                        'Verify your Merchant ID is valid',
                        'Ensure there are no extra spaces in credentials',
                        'Check the endpoint URL is correct for your environment'
                    ];
                    break;
                case 401:
                    $error_details['issue'] = 'Unauthorized - Invalid credentials';
                    $error_details['solutions'] = [
                        'Verify your API Key is correct and active',
                        'Check if your Merchant ID matches your account',
                        'Ensure you copied the complete API Key',
                        'Login to Viator Merchant Portal to verify credentials',
                        'Contact Viator Merchant support for verification'
                    ];
                    break;
                case 403:
                    $error_details['issue'] = 'Forbidden - Access denied';
                    $error_details['solutions'] = [
                        'Your API Key may not have merchant permissions',
                        'Verify your Merchant ID is associated with your API Key',
                        'Contact Viator to enable merchant API access',
                        'Check if your merchant account is in good standing'
                    ];
                    break;
                case 404:
                    $error_details['issue'] = 'Not Found - Merchant or endpoint not found';
                    $error_details['solutions'] = [
                        'Verify your Merchant ID is correct',
                        'Check if your merchant account is active',
                        'Ensure you are using the correct API endpoint',
                        'Contact Viator Merchant support to verify your account'
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
                    $error_details['issue'] = 'Server Error - Viator Merchant API issue';
                    $error_details['solutions'] = [
                        'This is a temporary server error',
                        'Wait a few minutes and try again',
                        'Check Viator Merchant API status',
                        'Try again later if issue persists'
                    ];
                    break;
                default:
                    $error_details['issue'] = 'Unexpected HTTP response';
                    $error_details['solutions'] = [
                        'Check your network connectivity',
                        'Verify both API Key and Merchant ID are correct',
                        'Try again in a few minutes',
                        'Check Viator Merchant API documentation'
                    ];
            }

            $response['data'] = $error_details;
            $response['message'] = "Authentication failed - HTTP {$http_code}";
            throw new Exception("HTTP {$http_code} error from Viator Merchant API");
        }

    } catch (Exception $e) {
        $response['debug']['validation_steps'][] = '[ERROR] ' . $e->getMessage();

        if (!isset($response['data']) || $response['data'] === null) {
            $response['data'] = [
                'error_type' => 'validation_error',
                'error_code' => 'credential_validation_failed',
                'error_description' => $e->getMessage(),
                'quick_fixes' => [
                    'Ensure you have valid Viator Merchant credentials',
                    'Check both API Key and Merchant ID',
                    'Verify network connectivity to Viator servers',
                    'Get credentials from Viator Merchant Portal',
                    'Contact Viator Merchant support if issues persist'
                ]
            ];
        }

        if (empty($response['message'])) {
            $response['message'] = 'Viator Merchant API credential validation failed';
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

<?php

// Set proper headers for JSON response
global $router;

$router->post('flights/kiwi/creds', function() {

function processCredentials() {
    // Start timing for performance metrics
    $start_time = microtime(true);

    // Initialize response data
    $response = [
        'success' => false,
        'message' => '',
        'data' => null,
        'metadata' => [
            'module' => 'kiwi',
            'service' => 'flights',
            'provider' => 'Kiwi.com Tequila API',
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
        $response['debug']['validation_steps'][] = '[START] Starting Kiwi.com Tequila API credential validation';

        // Get credentials from POST data
        $affil_id = trim($_POST['c1'] ?? '');     // AffilID
        $api_key = trim($_POST['c2'] ?? '');      // API Key
        $environment = trim($_POST['env'] ?? 'production');

        // Update environment in metadata
        $response['metadata']['environment'] = $environment;

        $response['debug']['validation_steps'][] = '[INFO] Parsing submitted credentials';
        $response['debug']['validation_steps'][] = "[ENV] Environment: PRODUCTION (Kiwi.com has only production)";

        // Validate required credentials
        if (empty($affil_id) || empty($api_key)) {
            $response['debug']['validation_steps'][] = '[ERROR] Credential validation failed - missing required fields';

            $missing_fields = [];
            if (empty($affil_id)) $missing_fields[] = 'AffilID (c1)';
            if (empty($api_key)) $missing_fields[] = 'API Key (c2)';

            throw new Exception('Missing required credentials: ' . implode(', ', $missing_fields));
        }

        // Check if using test values
        if ($api_key === 'test') {
            $response['debug']['validation_steps'][] = '[WARNING] Test credentials detected';
            $response['success'] = false;
            $response['message'] = 'Test credentials provided - cannot validate with Kiwi.com';
            $response['data'] = [
                'error_type' => 'test_credentials',
                'error_code' => 'test_values_not_allowed',
                'error_description' => 'Cannot validate with test credentials. Please use your actual Kiwi.com API credentials.',
                'provider_details' => [
                    'provider' => 'Kiwi.com Tequila API',
                    'environment' => 'production',
                    'documentation' => 'https://tequila.kiwi.com/portal/docs/tequila_api'
                ],
                'expected_format' => [
                    'c1' => 'Your AffilID (e.g., booknowtravelapp)',
                    'c2' => 'Your API Key (32-character alphanumeric)',
                    'example_c2' => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
                ],
                'troubleshooting' => [
                    'Replace "test" values with real Kiwi.com credentials',
                    'Get your API Key from https://tequila.kiwi.com/portal/login',
                    'Ensure your API Key is active',
                    'Contact Kiwi.com support if you need new credentials'
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
        $response['debug']['validation_steps'][] = '[KEY] AffilID: ' . $affil_id;
        $response['debug']['validation_steps'][] = '[KEY] API Key: ' . substr($api_key, 0, 8) . '************************ (length: ' . strlen($api_key) . ')';

        // Validate API Key format (typically 32 characters)
        if (strlen($api_key) < 20) {
            $response['debug']['validation_steps'][] = '[WARNING] API Key seems too short (expected ~32 characters)';
        } else {
            $response['debug']['validation_steps'][] = '[OK] API Key format looks valid';
        }

        // Kiwi.com API endpoint
        $base_endpoint = 'https://api.tequila.kiwi.com';
        $response['debug']['endpoint_used'] = $base_endpoint;
        $response['debug']['validation_steps'][] = '[API] Base endpoint: ' . $base_endpoint;
        $response['debug']['validation_steps'][] = '[CONNECT] Establishing connection to Kiwi.com Tequila API...';

        // Test API with locations endpoint (lightweight endpoint for testing)
        $test_endpoint = $base_endpoint . '/locations/query?term=NYC&locale=en-US&location_types=airport&limit=1';

        $response['debug']['validation_steps'][] = '[REQUEST] Testing API with locations query...';
        $response['debug']['validation_steps'][] = '[TEST] GET /locations/query?term=NYC';

        // Initialize cURL
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $test_endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'apikey: ' . $api_key,
                'Accept: application/json',
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
            // Check for valid response with locations data
            if (isset($api_data['locations']) && is_array($api_data['locations'])) {
                $response['debug']['validation_steps'][] = '[SUCCESS] Authentication successful! API returned valid data';
                $response['debug']['validation_steps'][] = '[DATA] Found ' . count($api_data['locations']) . ' location(s) in test response';

                // Success response
                $response['success'] = true;
                $response['message'] = 'Kiwi.com Tequila API credentials validated successfully';
                $response['data'] = [
                    'connection_status' => 'connected',
                    'authentication' => [
                        'status' => 'authenticated',
                        'auth_type' => 'API Key',
                        'affil_id' => $affil_id,
                        'api_key_preview' => substr($api_key, 0, 8) . '...'
                    ],
                    'api_details' => [
                        'provider' => 'Kiwi.com Tequila API',
                        'api_version' => 'v2',
                        'endpoint' => $base_endpoint,
                        'response_time' => round($total_time * 1000, 2) . 'ms',
                        'supported_services' => [
                            'Flight Search',
                            'Multi-city Flights',
                            'Flight Booking',
                            'Location Search',
                            'Airlines Data',
                            'Nomad Search'
                        ]
                    ],
                    'test_result' => [
                        'test_type' => 'locations_query',
                        'test_query' => 'NYC',
                        'results_count' => count($api_data['locations']),
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
                'error_description' => "HTTP {$http_code} error from Kiwi.com API: {$error_message}",
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
                        'Try regenerating your API Key from Kiwi.com portal'
                    ];
                    break;
                case 401:
                    $error_details['issue'] = 'Unauthorized - Invalid API Key';
                    $error_details['solutions'] = [
                        'Verify your API Key is correct and active',
                        'Check if your API Key has expired',
                        'Ensure you copied the complete API Key',
                        'Generate a new API Key from https://tequila.kiwi.com/portal',
                        'Contact Kiwi.com support for API access verification'
                    ];
                    break;
                case 403:
                    $error_details['issue'] = 'Forbidden - API access denied';
                    $error_details['solutions'] = [
                        'Your API Key may not have sufficient permissions',
                        'Contact Kiwi.com to enable full API access',
                        'Verify your account is in good standing',
                        'Check if there are any usage restrictions on your account'
                    ];
                    break;
                case 429:
                    $error_details['issue'] = 'Rate Limit Exceeded';
                    $error_details['solutions'] = [
                        'You have exceeded API rate limits',
                        'Wait a few minutes and try again',
                        'Consider upgrading your API plan for higher limits',
                        'Contact Kiwi.com about rate limit increases'
                    ];
                    break;
                case 500:
                case 502:
                case 503:
                    $error_details['issue'] = 'Server Error - Kiwi.com API issue';
                    $error_details['solutions'] = [
                        'This is a temporary server error',
                        'Wait a few minutes and try again',
                        'Check Kiwi.com API status',
                        'Try again later if issue persists'
                    ];
                    break;
                default:
                    $error_details['issue'] = 'Unexpected HTTP response';
                    $error_details['solutions'] = [
                        'Check your network connectivity',
                        'Verify your credentials are correct',
                        'Try again in a few minutes',
                        'Check Kiwi.com API documentation'
                    ];
            }

            $response['data'] = $error_details;
            $response['message'] = "Authentication failed - HTTP {$http_code}";
            throw new Exception("HTTP {$http_code} error from Kiwi.com API");
        }

    } catch (Exception $e) {
        $response['debug']['validation_steps'][] = '[ERROR] ' . $e->getMessage();

        if (!isset($response['data']) || $response['data'] === null) {
            $response['data'] = [
                'error_type' => 'validation_error',
                'error_code' => 'credential_validation_failed',
                'error_description' => $e->getMessage(),
                'quick_fixes' => [
                    'Ensure you have valid Kiwi.com API credentials',
                    'Check your AffilID and API Key',
                    'Verify network connectivity to Kiwi.com servers',
                    'Get API Key from https://tequila.kiwi.com/portal',
                    'Contact Kiwi.com support if issues persist'
                ]
            ];
        }

        if (empty($response['message'])) {
            $response['message'] = 'Kiwi.com API credential validation failed';
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

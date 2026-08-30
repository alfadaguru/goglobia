<?php

global $router;

$router->post('flights/pkfare/creds', function() {

function processCredentials() {
    // Start timing for performance metrics
    $start_time = microtime(true);

    // Initialize response data
    $response = [
        'success' => false,
        'message' => '',
        'data' => null,
        'metadata' => [
            'module' => 'pkfare',
            'service' => 'flights',
            'provider' => 'PKFare API',
            'api_version' => 'v4',
            'timestamp' => date('c'),
            'response_time_ms' => 0,
            'environment' => 'production'
        ],
        'debug' => [
            'validation_steps' => [],
            'api_request' => null,
            'api_response' => null,
            'endpoint_used' => null,
            'request_method' => 'POST'
        ]
    ];

    try {
        $response['debug']['validation_steps'][] = '[START] Starting PKFare API credential validation';

        // Get credentials from POST data
        $partner_id = trim($_POST['c1'] ?? '');      // Partner ID
        $api_key = trim($_POST['c2'] ?? '');         // API Key (base64)
        $environment = trim($_POST['env'] ?? 'production');
        $custom_url = trim($_POST['c3'] ?? '');      // Custom API URL (optional)

        // Update environment in metadata
        $response['metadata']['environment'] = $environment;

        $response['debug']['validation_steps'][] = '[INFO] Parsing submitted credentials';

        // Determine which environment to use
        if ($environment === 'test' || $environment === 'dev') {
            $base_endpoint = 'https://api.pkfare.com';
            $response['debug']['validation_steps'][] = "[ENV] Environment: TEST/DEVELOPMENT";
        } else {
            // Use custom URL if provided, otherwise default to production
            $base_endpoint = !empty($custom_url) ? rtrim($custom_url, '/') : 'https://api.pkfare.com';
            $response['debug']['validation_steps'][] = "[ENV] Environment: PRODUCTION";
            if (!empty($custom_url)) {
                $response['debug']['validation_steps'][] = "[ENV] Custom URL: $custom_url";
            }
        }

        // Validate required credentials
        if (empty($partner_id) || empty($api_key)) {
            $response['debug']['validation_steps'][] = '[ERROR] Credential validation failed - missing required fields';

            $missing_fields = [];
            if (empty($partner_id)) $missing_fields[] = 'Partner ID (c1)';
            if (empty($api_key)) $missing_fields[] = 'API Key (c2)';

            throw new Exception('Missing required credentials: ' . implode(', ', $missing_fields));
        }

        // Check if using test values
        if ($partner_id === 'test' || $api_key === 'test') {
            $response['debug']['validation_steps'][] = '[WARNING] Test credentials detected';
            $response['success'] = false;
            $response['message'] = 'Test credentials provided - cannot validate with PKFare';
            $response['data'] = [
                'error_type' => 'test_credentials',
                'error_code' => 'test_values_not_allowed',
                'error_description' => 'Cannot validate with test credentials. Please use your actual PKFare credentials.',
                'provider_details' => [
                    'provider' => 'PKFare API',
                    'environment' => $environment,
                    'documentation' => 'https://www.pkfare.com/open/en/index.html'
                ],
                    'expected_format' => [
                    'c1' => 'Partner ID (base64 encoded string)',
                    'c2' => 'API Key (base64 encoded string)',
                    'c3' => 'Custom API URL (optional)',
                    'example_c1' => 'EXAMPLE_PARTNER_ID_BASE64',
                    'example_c2' => 'EXAMPLE_API_KEY_BASE64'
                ],
                'troubleshooting' => [
                    'Replace "test" values with your real PKFare credentials',
                    'Get your credentials from PKFare partner portal',
                    'Ensure both Partner ID and API Key are base64 encoded',
                    'Contact PKFare support if you need new credentials'
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
        $response['debug']['validation_steps'][] = '[KEY] Partner ID: ' . substr($partner_id, 0, 10) . '...' . substr($partner_id, -4);
        $response['debug']['validation_steps'][] = '[KEY] API Key: ' . str_repeat('*', strlen($api_key));

        // Generate signature (MD5 hash of Partner ID + API Key)
        $signature = md5($partner_id . $api_key);
        $response['debug']['validation_steps'][] = '[KEY] Signature generated: ' . substr($signature, 0, 16) . '...';

        // Test endpoint - using shopping API with minimal search
        $test_endpoint = $base_endpoint . '/json/shoppingV4';
        
        $response['debug']['endpoint_used'] = $test_endpoint;
        $response['debug']['validation_steps'][] = '[API] Test endpoint: ' . $test_endpoint;
        $response['debug']['validation_steps'][] = '[CONNECT] Establishing connection to PKFare API...';

        // Create a minimal test search request (7 days in the future, simple one-way)
        $test_departure_date = date('Y-m-d', strtotime('+7 days'));
        
        $test_request = json_encode([
            'authentication' => [
                'partnerId' => $partner_id,
                'sign' => $signature
            ],
            'search' => [
                'adults' => 1,
                'children' => 0,
                'infants' => 0,
                'nonstop' => 0,
                'airline' => '',
                'solutions' => 10,
                'searchAirLegs' => [
                    [
                        'cabinClass' => 'Economy',
                        'departureDate' => $test_departure_date,
                        'destination' => 'DXB',
                        'origin' => 'LHR'
                    ]
                ]
            ]
        ]);

        $response['debug']['validation_steps'][] = '[REQUEST] Testing credentials with minimal flight search...';
        $response['debug']['validation_steps'][] = '[TEST] Route: LHR → DXB';
        $response['debug']['validation_steps'][] = '[TEST] Date: ' . $test_departure_date;
        $response['debug']['validation_steps'][] = '[TEST] Passengers: 1 Adult';

        // Store request for debugging (truncated)
        $response['debug']['api_request'] = substr($test_request, 0, 500) . (strlen($test_request) > 500 ? '...' : '');

        // Initialize cURL
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $test_endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $test_request,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: PHPTravels-v10/1.0'
            ],
            CURLOPT_TIMEOUT => 60,
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
                throw new Exception('Invalid JSON response from PKFare API');
            }

            // Check PKFare specific error message
            $error_msg = $api_data['errorMsg'] ?? '';
            $error_code = $api_data['errorCode'] ?? '';

            if ($error_msg === 'ok' || $error_code === '0' || $error_code === 0) {
                // Success - credentials are valid
                $response['debug']['validation_steps'][] = '[SUCCESS] Authentication successful!';
                $response['debug']['validation_steps'][] = '[DATA] PKFare API responded with valid data';

                // Check if we got flight results
                $solutions_count = 0;
                if (isset($api_data['data']['solutions']) && is_array($api_data['data']['solutions'])) {
                    $solutions_count = count($api_data['data']['solutions']);
                }

                $response['success'] = true;
                $response['message'] = 'PKFare API credentials validated successfully';
                $response['data'] = [
                    'connection_status' => 'connected',
                    'authentication' => [
                        'status' => 'authenticated',
                        'auth_type' => 'Signature-based (MD5)',
                        'environment' => $environment,
                        'partner_id' => substr($partner_id, 0, 10) . '...' . substr($partner_id, -4)
                    ],
                    'api_details' => [
                        'provider' => 'PKFare',
                        'api_version' => 'v4 (shoppingV4)',
                        'api_type' => 'REST API',
                        'endpoint' => $base_endpoint,
                        'response_time' => round($total_time * 1000, 2) . 'ms',
                        'supported_services' => [
                            'Flight Search (shoppingV4)',
                            'Precise Pricing (precisePricing_V6)',
                            'Precise Booking (preciseBooking_V6)',
                            'Ticketing',
                            'Order Details (orderDetail/v8)',
                            'Order Cancellation'
                        ]
                    ],
                    'test_result' => [
                        'test_type' => 'flight_search',
                        'test_endpoint' => '/json/shoppingV4',
                        'test_route' => 'LHR → DXB',
                        'api_responsive' => true,
                        'credentials_valid' => true,
                        'solutions_found' => $solutions_count,
                        'connection_time' => round($connect_time * 1000, 2) . 'ms'
                    ]
                ];

            } else {
                // PKFare returned an error
                $response['debug']['validation_steps'][] = "[ERROR] PKFare API returned error: $error_msg";
                
                $error_details = [
                    'error_type' => 'authentication_error',
                    'error_code' => $error_code,
                    'error_message' => $error_msg,
                    'http_details' => [
                        'status_code' => $http_code,
                        'endpoint_used' => $test_endpoint,
                        'connection_time' => round($connect_time * 1000, 2) . 'ms',
                        'total_time' => round($total_time * 1000, 2) . 'ms'
                    ]
                ];

                // Add specific troubleshooting based on error
                if (strpos($error_msg, 'sign') !== false || strpos($error_msg, 'authentication') !== false) {
                    $error_details['issue'] = 'Authentication Failed - Invalid credentials or signature';
                    $error_details['solutions'] = [
                        'Verify your Partner ID is correct and base64 encoded',
                        'Check if your API Key is correct and base64 encoded',
                        'Ensure credentials match your PKFare account',
                        'Signature is calculated as: MD5(partnerId + apiKey)',
                        'Contact PKFare support for credential verification'
                    ];
                } else {
                    $error_details['issue'] = 'PKFare API Error: ' . $error_msg;
                    $error_details['solutions'] = [
                        'Check the error message for specific details',
                        'Verify your credentials are active and not expired',
                        'Ensure your account has proper permissions',
                        'Contact PKFare support for assistance'
                    ];
                }

                $response['data'] = $error_details;
                $response['message'] = "Authentication failed - " . $error_msg;
                throw new Exception("PKFare API error: " . $error_msg);
            }

        } else {
            $response['debug']['validation_steps'][] = "[ERROR] Authentication failed with HTTP {$http_code}";

            // Try to parse error response
            $error_data = json_decode($api_response, true);

            $error_details = [
                'error_type' => 'authentication_error',
                'error_code' => 'http_' . $http_code,
                'error_description' => "HTTP {$http_code} error from PKFare API",
                'http_details' => [
                    'status_code' => $http_code,
                    'endpoint_used' => $test_endpoint,
                    'connection_time' => round($connect_time * 1000, 2) . 'ms',
                    'total_time' => round($total_time * 1000, 2) . 'ms'
                ]
            ];

            // Add PKFare error details if available
            if ($error_data && isset($error_data['errorMsg'])) {
                $error_details['api_error'] = $error_data['errorMsg'];
                $error_details['api_error_code'] = $error_data['errorCode'] ?? '';
            }

            // Add specific troubleshooting based on HTTP status
            switch ($http_code) {
                case 400:
                    $error_details['issue'] = 'Bad Request - Invalid request format or parameters';
                    $error_details['solutions'] = [
                        'Check if your Partner ID format is correct',
                        'Verify the API Key format is correct',
                        'Ensure signature is calculated correctly (MD5 of partnerId + apiKey)',
                        'Check request JSON structure'
                    ];
                    break;
                case 401:
                case 403:
                    $error_details['issue'] = 'Unauthorized - Invalid credentials or signature';
                    $error_details['solutions'] = [
                        'Verify your Partner ID is correct and active',
                        'Check if your API Key is correct',
                        'Ensure credentials match your PKFare account',
                        'Verify signature is calculated correctly (MD5 of partnerId + apiKey)',
                        'Contact PKFare support for credential verification'
                    ];
                    break;
                case 404:
                    $error_details['issue'] = 'Not Found - Endpoint not available';
                    $error_details['solutions'] = [
                        'Check if the API URL is correct',
                        'Verify you are using the correct endpoint path',
                        'Ensure custom URL (c3) is properly formatted if provided',
                        'Contact PKFare for correct API endpoint'
                    ];
                    break;
                case 429:
                    $error_details['issue'] = 'Rate Limit Exceeded';
                    $error_details['solutions'] = [
                        'You have exceeded the API rate limit',
                        'Wait a few minutes before trying again',
                        'Check your API usage limits',
                        'Contact PKFare to increase your rate limits'
                    ];
                    break;
                case 500:
                case 502:
                case 503:
                    $error_details['issue'] = 'Server Error - PKFare API issue';
                    $error_details['solutions'] = [
                        'This is a temporary server error',
                        'Wait a few minutes and try again',
                        'Check PKFare API status',
                        'Try again later if issue persists'
                    ];
                    break;
                default:
                    $error_details['issue'] = 'Unexpected HTTP response';
                    $error_details['solutions'] = [
                        'Check your network connectivity',
                        'Verify both Partner ID and API Key are correct',
                        'Ensure signature is calculated correctly',
                        'Try again in a few minutes',
                        'Contact PKFare support'
                    ];
            }

            $response['data'] = $error_details;
            $response['message'] = "Authentication failed - HTTP {$http_code}";
            throw new Exception("HTTP {$http_code} error from PKFare API");
        }

    } catch (Exception $e) {
        $response['debug']['validation_steps'][] = '[ERROR] ' . $e->getMessage();

        if (!isset($response['data']) || $response['data'] === null) {
            $response['data'] = [
                'error_type' => 'validation_error',
                'error_code' => 'credential_validation_failed',
                'error_description' => $e->getMessage(),
                'quick_fixes' => [
                    'Ensure you have valid PKFare credentials',
                    'Check both Partner ID and API Key are base64 encoded',
                    'Verify signature calculation (MD5 of partnerId + apiKey)',
                    'Verify network connectivity to PKFare servers',
                    'Get credentials from PKFare partner portal',
                    'Contact PKFare support if issues persist'
                ]
            ];
        }

        if (empty($response['message'])) {
            $response['message'] = 'PKFare API credential validation failed';
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

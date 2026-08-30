<?php

global $router;

$router->post('stays/amadeus/creds', function() {

function processCredentials() {
    // Start timing for performance metrics
    $start_time = microtime(true);

    // Initialize response data
    $response = [
        'success' => false,
        'message' => '',
        'data' => null,
        'metadata' => [
            'module' => 'amadeus',
            'service' => 'hotels',
            'provider' => 'Amadeus Self-Service API',
            'api_version' => 'v1',
            'timestamp' => date('c'),
            'response_time_ms' => 0,
            'environment' => 'test'
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
        $response['debug']['validation_steps'][] = '[START] Starting Amadeus REST API credential validation';

        // Get credentials from POST data
        $api_key = trim($_POST['c1'] ?? '');      // Client ID / API Key
        $api_secret = trim($_POST['c2'] ?? '');   // Client Secret / API Secret
        $environment = trim($_POST['env'] ?? 'test');

        // Update environment in metadata
        $response['metadata']['environment'] = $environment;

        $response['debug']['validation_steps'][] = '[INFO] Parsing submitted credentials';

        // Determine which environment to use
        if ($environment === 'production') {
            $base_endpoint = 'https://api.amadeus.com';
            $response['debug']['validation_steps'][] = "[ENV] Environment: PRODUCTION";
        } else {
            $base_endpoint = 'https://test.api.amadeus.com';
            $response['debug']['validation_steps'][] = "[ENV] Environment: TEST/DEVELOPMENT";
        }

        // Validate required credentials
        if (empty($api_key) || empty($api_secret)) {
            $response['debug']['validation_steps'][] = '[ERROR] Credential validation failed - missing required fields';

            $missing_fields = [];
            if (empty($api_key)) $missing_fields[] = 'API Key / Client ID (c1)';
            if (empty($api_secret)) $missing_fields[] = 'API Secret / Client Secret (c2)';

            throw new Exception('Missing required credentials: ' . implode(', ', $missing_fields));
        }

        // Check if using test values
        if ($api_key === 'test' || $api_secret === 'test') {
            $response['debug']['validation_steps'][] = '[WARNING] Test credentials detected';
            $response['success'] = false;
            $response['message'] = 'Test credentials provided - cannot validate with Amadeus';
            $response['data'] = [
                'error_type' => 'test_credentials',
                'error_code' => 'test_values_not_allowed',
                'error_description' => 'Cannot validate with test credentials. Please use your actual Amadeus API credentials.',
                'provider_details' => [
                    'provider' => 'Amadeus Self-Service API',
                    'environment' => $environment,
                    'documentation' => 'https://developers.amadeus.com/'
                ],
                'expected_format' => [
                    'c1' => 'Your Amadeus API Key / Client ID',
                    'c2' => 'Your Amadeus API Secret / Client Secret',
                    'example_c1' => 'abc123def456ghi789',
                    'example_c2' => 'Xy9ZaBcD1EfG2h'
                ],
                'troubleshooting' => [
                    'Replace "test" values with your real Amadeus credentials',
                    'Get your credentials from Amadeus for Developers portal',
                    'Create a Self-Service app at https://developers.amadeus.com/my-apps',
                    'Ensure both API Key and Secret are active',
                    'Contact Amadeus support if you need assistance'
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
        $response['debug']['validation_steps'][] = '[KEY] API Key: ' . substr($api_key, 0, 8) . '...' . substr($api_key, -4);
        $response['debug']['validation_steps'][] = '[KEY] API Secret: ' . str_repeat('*', strlen($api_secret));

        // Step 1: Get OAuth2 Access Token
        $token_endpoint = $base_endpoint . '/v1/security/oauth2/token';

        $response['debug']['endpoint_used'] = $token_endpoint;
        $response['debug']['validation_steps'][] = '[API] Token endpoint: ' . $token_endpoint;
        $response['debug']['validation_steps'][] = '[CONNECT] Establishing connection to Amadeus API...';
        $response['debug']['validation_steps'][] = '[REQUEST] Requesting OAuth2 access token...';

        // Prepare token request data
        $token_data = [
            'grant_type' => 'client_credentials',
            'client_id' => $api_key,
            'client_secret' => $api_secret
        ];

        // Initialize cURL for token request
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $token_endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($token_data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: PHPTravels-v10/1.0'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true
        ]);

        // Execute the token request
        $token_response = curl_exec($ch);
        $token_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $connect_time = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        $total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);


        $response['debug']['validation_steps'][] = "[RESPONSE] Token API Response: HTTP {$token_http_code} (connect: {$connect_time}s, total: {$total_time}s)";

        if ($curl_error) {
            $response['debug']['validation_steps'][] = '[ERROR] Network error occurred';
            throw new Exception('Network Error: ' . $curl_error);
        }

        // Store response for debugging (first 1000 chars)
        $response['debug']['api_response'] = substr($token_response, 0, 1000) . (strlen($token_response) > 1000 ? '...' : '');

        // Parse JSON response
        if ($token_http_code === 200) {
            $token_data = json_decode($token_response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $response['debug']['validation_steps'][] = '[ERROR] Failed to parse JSON response';
                throw new Exception('Invalid JSON response from Amadeus API');
            }

            // Verify access token exists
            if (!isset($token_data['access_token'])) {
                $response['debug']['validation_steps'][] = '[ERROR] No access token in response';
                throw new Exception('No access token received from Amadeus API');
            }

            $access_token = $token_data['access_token'];
            $token_type = $token_data['token_type'] ?? 'Bearer';
            $expires_in = $token_data['expires_in'] ?? 1799;

            $response['debug']['validation_steps'][] = '[SUCCESS] OAuth2 authentication successful!';
            $response['debug']['validation_steps'][] = '[TOKEN] Access token obtained';
            $response['debug']['validation_steps'][] = "[TOKEN] Token type: $token_type";
            $response['debug']['validation_steps'][] = "[TOKEN] Expires in: {$expires_in}s (" . round($expires_in/60, 1) . " minutes)";

            // Step 2: Test the token with a simple API call (Hotels by City)
            $response['debug']['validation_steps'][] = '[TEST] Testing token with Hotels by City API...';

            // Use a known city code for testing
            $test_city = 'PAR'; // Paris

            $test_params = [
                'cityCode' => $test_city,
                'radius' => 5,
                'radiusUnit' => 'KM',
                'hotelSource' => 'ALL'
            ];

            $test_endpoint = $base_endpoint . '/v1/reference-data/locations/hotels/by-city?' . http_build_query($test_params);

            $response['debug']['validation_steps'][] = "[TEST] Endpoint: /v1/reference-data/locations/hotels/by-city";
            $response['debug']['validation_steps'][] = "[TEST] City: $test_city, Radius: 5 KM";

            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => $test_endpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Authorization: $token_type $access_token",
                    'Accept: application/json',
                    'User-Agent: PHPTravels-v10/1.0'
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => true
            ]);

            $test_response = curl_exec($ch);
            $test_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $test_connect_time = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
            $test_total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);


            $response['debug']['validation_steps'][] = "[RESPONSE] Hotels API Response: HTTP {$test_http_code} (connect: {$test_connect_time}s, total: {$test_total_time}s)";

            if ($test_http_code === 200) {
                $test_data = json_decode($test_response, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    $hotel_count = isset($test_data['data']) ? count($test_data['data']) : 0;

                    $response['debug']['validation_steps'][] = "[SUCCESS] Hotels API test successful!";
                    $response['debug']['validation_steps'][] = "[DATA] Found $hotel_count hotels in test search";

                    // Success response
                    $response['success'] = true;
                    $response['message'] = 'Amadeus API credentials validated successfully';
                    $response['data'] = [
                        'connection_status' => 'connected',
                        'authentication' => [
                            'status' => 'authenticated',
                            'auth_type' => 'OAuth2 (client_credentials)',
                            'token_type' => $token_type,
                            'token_expires_in' => $expires_in . 's',
                            'environment' => $environment,
                            'api_key' => substr($api_key, 0, 8) . '...' . substr($api_key, -4)
                        ],
                        'api_details' => [
                            'provider' => 'Amadeus',
                            'api_version' => 'Self-Service v1',
                            'api_type' => 'REST API',
                            'endpoint' => $base_endpoint,
                            'token_response_time' => round($total_time * 1000, 2) . 'ms',
                            'test_response_time' => round($test_total_time * 1000, 2) . 'ms',
                            'supported_services' => [
                                'Hotel Search by City',
                                'Hotel Search by Geocode',
                                'Hotel List',
                                'Hotel Offers & Pricing',
                                'Hotel Booking',
                                'Hotel Ratings & Sentiments',
                                'Safe Place (COVID-19)',
                                'Points of Interest'
                            ]
                        ],
                        'test_result' => [
                            'test_type' => 'hotel_search',
                            'test_endpoint' => '/v1/reference-data/locations/hotels/by-city',
                            'test_city' => $test_city,
                            'api_responsive' => true,
                            'credentials_valid' => true,
                            'hotels_found' => $hotel_count,
                            'connection_time' => round($test_connect_time * 1000, 2) . 'ms'
                        ]
                    ];

                } else {
                    $response['debug']['validation_steps'][] = '[WARNING] Could not parse test API response';
                    throw new Exception('Invalid JSON response from Hotels API');
                }

            } else {
                $response['debug']['validation_steps'][] = "[ERROR] Hotels API test failed with HTTP {$test_http_code}";

                // Token works but API test failed - still consider it a success
                $response['success'] = true;
                $response['message'] = 'Amadeus credentials valid (OAuth2 successful, API test returned HTTP ' . $test_http_code . ')';
                $response['data'] = [
                    'connection_status' => 'connected',
                    'authentication' => [
                        'status' => 'authenticated',
                        'auth_type' => 'OAuth2 (client_credentials)',
                        'token_type' => $token_type,
                        'token_expires_in' => $expires_in . 's',
                        'environment' => $environment,
                        'api_key' => substr($api_key, 0, 8) . '...' . substr($api_key, -4)
                    ],
                    'api_details' => [
                        'provider' => 'Amadeus',
                        'api_version' => 'Self-Service v1',
                        'api_type' => 'REST API',
                        'endpoint' => $base_endpoint,
                        'token_response_time' => round($total_time * 1000, 2) . 'ms'
                    ],
                    'test_result' => [
                        'test_type' => 'oauth2_token',
                        'api_responsive' => true,
                        'credentials_valid' => true,
                        'note' => 'OAuth2 authentication successful. API test returned HTTP ' . $test_http_code
                    ]
                ];
            }

        } else {
            $response['debug']['validation_steps'][] = "[ERROR] Authentication failed with HTTP {$token_http_code}";

            // Try to parse error response
            $error_data = json_decode($token_response, true);

            $error_details = [
                'error_type' => 'authentication_error',
                'error_code' => 'http_' . $token_http_code,
                'error_description' => "HTTP {$token_http_code} error from Amadeus OAuth2",
                'http_details' => [
                    'status_code' => $token_http_code,
                    'endpoint_used' => $token_endpoint,
                    'connection_time' => round($connect_time * 1000, 2) . 'ms',
                    'total_time' => round($total_time * 1000, 2) . 'ms'
                ]
            ];

            // Add API error details if available
            if ($error_data) {
                if (isset($error_data['error'])) {
                    $error_details['api_error'] = $error_data['error'];
                }
                if (isset($error_data['error_description'])) {
                    $error_details['api_error_description'] = $error_data['error_description'];
                }
            }

            // Add specific troubleshooting based on HTTP status
            switch ($token_http_code) {
                case 400:
                    $error_details['issue'] = 'Bad Request - Invalid credentials format or parameters';
                    $error_details['solutions'] = [
                        'Check if your API Key (Client ID) is correct',
                        'Verify the API Secret (Client Secret) is correct',
                        'Ensure both credentials are from the same Amadeus app',
                        'Verify grant_type is set to client_credentials'
                    ];
                    break;
                case 401:
                    $error_details['issue'] = 'Unauthorized - Invalid API credentials';
                    $error_details['solutions'] = [
                        'Verify your API Key (Client ID) is correct and active',
                        'Check if your API Secret (Client Secret) is correct',
                        'Ensure credentials match your Amadeus Self-Service app',
                        'Verify your app is not suspended or disabled',
                        'Get new credentials from https://developers.amadeus.com/my-apps'
                    ];
                    break;
                case 429:
                    $error_details['issue'] = 'Rate Limit Exceeded';
                    $error_details['solutions'] = [
                        'You have exceeded the API rate limit',
                        'Wait a few minutes before trying again',
                        'Check your API usage quotas',
                        'Consider upgrading your Amadeus plan'
                    ];
                    break;
                case 500:
                case 502:
                case 503:
                    $error_details['issue'] = 'Server Error - Amadeus API issue';
                    $error_details['solutions'] = [
                        'This is a temporary server error',
                        'Wait a few minutes and try again',
                        'Check Amadeus API status',
                        'Try again later if issue persists'
                    ];
                    break;
                default:
                    $error_details['issue'] = 'Unexpected HTTP response';
                    $error_details['solutions'] = [
                        'Check your network connectivity',
                        'Verify both API Key and Secret are correct',
                        'Ensure you are using correct environment (test/production)',
                        'Try again in a few minutes',
                        'Contact Amadeus support at https://developers.amadeus.com/support'
                    ];
            }

            $response['data'] = $error_details;
            $response['message'] = "Authentication failed - HTTP {$token_http_code}";
            throw new Exception("HTTP {$token_http_code} error from Amadeus OAuth2");
        }

    } catch (Exception $e) {
        $response['debug']['validation_steps'][] = '[ERROR] ' . $e->getMessage();

        if (!isset($response['data']) || $response['data'] === null) {
            $response['data'] = [
                'error_type' => 'validation_error',
                'error_code' => 'credential_validation_failed',
                'error_description' => $e->getMessage(),
                'quick_fixes' => [
                    'Ensure you have valid Amadeus Self-Service credentials',
                    'Check both API Key (Client ID) and API Secret (Client Secret) are correct',
                    'Create a Self-Service app at https://developers.amadeus.com/my-apps',
                    'Verify network connectivity to Amadeus servers',
                    'Contact Amadeus support at https://developers.amadeus.com/support'
                ]
            ];
        }

        if (empty($response['message'])) {
            $response['message'] = 'Amadeus API credential validation failed';
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

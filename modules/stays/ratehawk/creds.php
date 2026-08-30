<?php

/**
 * Ratehawk API Credential Validator
 * Validates Ratehawk API credentials by testing authentication
 */
$router->post('/stays/ratehawk/creds', function() {
    // Start timing for performance metrics
    $start_time = microtime(true);

    // Initialize response data
    $response = [
        'success' => false,
        'message' => '',
        'data' => null,
        'metadata' => [
            'module' => 'ratehawk',
            'service' => 'hotels',
            'provider' => 'Ratehawk API',
            'api_version' => '2.0',
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
        $response['debug']['validation_steps'][] = '[START] Starting Ratehawk API credential validation';

        // Get and sanitize credentials; support both form-data and JSON payloads
        $raw_body = file_get_contents('php://input');
        $decoded = json_decode($raw_body, true);

        // Prefer $_POST when available, otherwise fallback to JSON body
        $payload = is_array($decoded) ? array_merge($decoded, $_POST) : $_POST;

        $key_id = isset($payload['c1']) ? trim($payload['c1']) : '';
        $key_type = isset($payload['c2']) ? trim($payload['c2']) : '';
        $api_key = isset($payload['c3']) ? trim($payload['c3']) : '';
        $environment = isset($payload['env']) ? trim($payload['env']) : 'production';

        $response['debug']['validation_steps'][] = '[PAYLOAD] Source detected: ' . (!empty($_POST) ? 'POST' : (is_array($decoded) ? 'JSON body' : 'empty'));

        // Validate input types
        if (!empty($key_id) && !is_numeric($key_id) && $key_id !== 'test') {
            throw new Exception('Key ID must be numeric. Received: ' . htmlspecialchars($key_id));
        }

        // Update environment in metadata
        $response['metadata']['environment'] = $environment;

        $response['debug']['validation_steps'][] = '[INFO] Parsing submitted credentials';

        // Determine which environment to use based on key_type or env
        $is_test = (strtolower($key_type) === 'test' || $environment === 'test');

        $base_url_input = isset($payload['c4']) ? rtrim(trim($payload['c4']), '/') : '';
        $base_endpoint = $base_url_input;
        if (stripos($base_endpoint, '/api/b2b/v3') === false) {
            $base_endpoint .= '/api/b2b/v3';
        }
        $response['debug']['validation_steps'][] = "[ENV] Base URL: " . $base_endpoint;

        // Validate required credentials
        if (empty($key_id) || empty($api_key) || empty($base_url_input)) {
            $response['debug']['validation_steps'][] = '[ERROR] Credential validation failed - missing required fields';

            $missing_fields = [];
            if (empty($key_id)) $missing_fields[] = 'Key ID (c1)';
            if (empty($api_key)) $missing_fields[] = 'API Key (c3)';
            if (empty($base_url_input)) $missing_fields[] = 'Base URL (c4)';

            throw new Exception('Missing required credentials: ' . implode(', ', $missing_fields));
        }

        // Check if using test values
        if ($key_id === 'test' || $api_key === 'test') {
            $response['debug']['validation_steps'][] = '[WARNING] Test credentials detected';

            $response['success'] = false;
            $response['message'] = 'Test credentials provided - cannot validate with Ratehawk';
            $response['data'] = [
                'error_type' => 'test_credentials',
                'error_code' => 'test_values_not_allowed',
                'error_description' => 'Cannot validate with test credentials. Please use your actual Ratehawk credentials.',
                'provider_details' => [
                    'provider' => 'Ratehawk API',
                    'environment' => $response['metadata']['environment'],
                    'documentation' => 'https://docs.emergya.travel/'
                ],
                'expected_format' => [
                    'c1' => 'Your Ratehawk Key ID (numeric)',
                    'c2' => 'Key Type (Test or Production)',
                    'c3' => 'Your Ratehawk API Key (UUID format)',
                    'example_c1' => '14223',
                    'example_c2' => 'Test',
                    'example_c3' => '75a34924-a1eb-4e82-b65b-1204c8382bf2'
                ],
                'troubleshooting' => [
                    'Replace "test" values with your real Ratehawk credentials',
                    'Get your credentials from Ratehawk Partner Portal',
                    'Ensure Key ID and API Key are active',
                    'Contact Ratehawk support if you need new credentials'
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
        $response['debug']['validation_steps'][] = '[KEY] Key ID: ' . $key_id;
        $response['debug']['validation_steps'][] = '[KEY] Key Type: ' . ($key_type ?: 'Not specified');
        // Show only minimal part of API key for security
        $response['debug']['validation_steps'][] = '[KEY] API Key: ' . substr($api_key, 0, 4) . '...' . substr($api_key, -4);

        // Ratehawk uses Basic Auth with Key ID and API Key
        $auth_string = base64_encode($key_id . ':' . $api_key);

        $response['debug']['validation_steps'][] = '[AUTH] Basic Auth credentials encoded';

        // Use a lightweight endpoint to test credentials
        // Ratehawk's /hotel/info endpoint with minimal data
        $test_endpoint = $base_endpoint . '/hotel/info/';

        $response['debug']['endpoint_used'] = $test_endpoint;
        $response['debug']['validation_steps'][] = '[API] Test endpoint: ' . $test_endpoint;
        $response['debug']['validation_steps'][] = '[CONNECT] Establishing connection to Ratehawk API...';

        // Test API with hotel info endpoint (validates credentials)
        $response['debug']['validation_steps'][] = '[REQUEST] Testing API credentials with /hotel/info endpoint...';
        $response['debug']['request_method'] = 'POST';

        // Minimal request body - just need to check auth
        $request_body = json_encode([
            'id' => 'test_hotel_1', // Dummy hotel ID to test auth
            'language' => 'en'
        ]);

        // Initialize cURL
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $test_endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request_body,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Basic ' . $auth_string,
                'User-Agent: PHPTravels-v10/1.0'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3
        ]);

        // Execute the request
        $api_response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $connect_time = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        $total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);

        if (function_exists('ratehawk_log')) {
            ratehawk_log('creds', 'validate', $request_body, $api_response, '', [
                'url' => $test_endpoint,
                'method' => 'POST',
                'headers' => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'Authorization: Basic ***',
                    'User-Agent: PHPTravels-v10/1.0'
                ],
                'response_headers' => ''
            ]);
        }


        $response['debug']['validation_steps'][] = "[RESPONSE] API Response: HTTP {$http_code} (connect: {$connect_time}s, total: {$total_time}s)";

        if ($curl_error) {
            $response['debug']['validation_steps'][] = '[ERROR] Network error occurred';
            throw new Exception('Network Error: ' . $curl_error);
        }

        // Store response for debugging (limited chars)
        $response['debug']['api_response'] = substr($api_response, 0, 500) . (strlen($api_response) > 500 ? '...' : '');

        // Parse JSON response
        $api_data = json_decode($api_response, true);

        // Ratehawk returns 200 even for auth errors, but with error in body
        // Check for successful authentication
        // HTTP 200 with valid response = success
        // HTTP 401 = invalid credentials
        // HTTP 200 with error.code = check specific error

        if ($http_code === 200) {
            // Check if response contains error
            if (isset($api_data['error']) || ($api_data['status'] ?? '') === 'error') {
                $raw_error = $api_data['error'] ?? null;
                $error_code = is_array($raw_error) ? ($raw_error['code'] ?? 'unknown') : ($raw_error ?? 'unknown');
                $error_message = is_array($raw_error) ? ($raw_error['message'] ?? 'Unknown error') : ($raw_error ?? 'Unknown error');
                $validation_error = $api_data['debug']['validation_error'] ?? null;

                // "hotel not found" or invalid test id still proves auth works
                $is_hotel_not_found = strpos(strtolower((string)$error_message), 'not found') !== false ||
                    strpos(strtolower((string)$error_code), 'not_found') !== false ||
                    (string)$error_code === 'hotel_not_found';
                $is_invalid_id = strtolower((string)$validation_error) === 'invalid id';

                if ($is_hotel_not_found || $is_invalid_id) {
                    $response['debug']['validation_steps'][] = '[SUCCESS] Authentication successful! (Hotel not found/invalid id is expected for test)';
                    $response['debug']['validation_steps'][] = '[DATA] Credentials are valid - API accepted the request';

                    // Success response
                    $response['success'] = true;
                    $response['message'] = 'Ratehawk API credentials validated successfully';
                    $response['data'] = [
                        'connection_status' => 'connected',
                        'authentication' => [
                            'status' => 'authenticated',
                            'auth_type' => 'Basic Auth (Key ID + API Key)',
                            'environment' => $is_test ? 'test' : 'production',
                            'key_id' => $key_id,
                            'key_type' => $key_type ?: 'Not specified'
                        ],
                        'api_details' => [
                            'provider' => 'Ratehawk (Emerging Travel Group)',
                            'api_version' => 'v3',
                            'api_type' => 'REST API',
                            'endpoint' => $base_endpoint,
                            'response_time' => round($total_time * 1000, 2) . 'ms',
                            'supported_services' => [
                                'Hotel Search',
                                'Hotel Details',
                                'Availability & Rates',
                                'Booking Creation',
                                'Booking Management',
                                'Order Status',
                                'Cancellation'
                            ]
                        ],
                        'test_result' => [
                            'test_type' => 'auth_validation',
                            'test_endpoint' => '/hotel/info/',
                            'api_responsive' => true,
                            'credentials_valid' => true,
                            'connection_time' => round($connect_time * 1000, 2) . 'ms',
                            'note' => 'Auth validated - hotel_not_found/invalid_id confirms API accepted credentials'
                        ]
                    ];
                } else {
                    // Other error - likely auth issue
                    $response['debug']['validation_steps'][] = "[ERROR] API returned error: {$error_code} - {$error_message}";
                    throw new Exception("Ratehawk API Error: {$error_message} (Code: {$error_code})");
                }
            } else {
                // No error in response - credentials valid
                $response['debug']['validation_steps'][] = '[SUCCESS] Authentication successful! API returned valid response';
                $response['debug']['validation_steps'][] = '[DATA] API is operational and credentials are valid';

                // Success response
                $response['success'] = true;
                $response['message'] = 'Ratehawk API credentials validated successfully';
                $response['data'] = [
                    'connection_status' => 'connected',
                    'authentication' => [
                        'status' => 'authenticated',
                        'auth_type' => 'Basic Auth (Key ID + API Key)',
                        'environment' => $is_test ? 'test' : 'production',
                        'key_id' => $key_id,
                        'key_type' => $key_type ?: 'Not specified'
                    ],
                    'api_details' => [
                        'provider' => 'Ratehawk (Emerging Travel Group)',
                        'api_version' => 'v3',
                        'api_type' => 'REST API',
                        'endpoint' => $base_endpoint,
                        'response_time' => round($total_time * 1000, 2) . 'ms',
                        'supported_services' => [
                            'Hotel Search',
                            'Hotel Details',
                            'Availability & Rates',
                            'Booking Creation',
                            'Booking Management',
                            'Order Status',
                            'Cancellation'
                        ]
                    ],
                    'test_result' => [
                        'test_type' => 'auth_validation',
                        'test_endpoint' => '/hotel/info/',
                        'api_responsive' => true,
                        'credentials_valid' => true,
                        'connection_time' => round($connect_time * 1000, 2) . 'ms'
                    ]
                ];
            }

        } else {
            $response['debug']['validation_steps'][] = "[ERROR] Authentication failed with HTTP {$http_code}";

            $error_details = [
                'error_type' => 'authentication_error',
                'error_code' => 'http_' . $http_code,
                'error_description' => "HTTP {$http_code} error from Ratehawk API",
                'http_details' => [
                    'status_code' => $http_code,
                    'endpoint_used' => $test_endpoint,
                    'connection_time' => round($connect_time * 1000, 2) . 'ms',
                    'total_time' => round($total_time * 1000, 2) . 'ms'
                ]
            ];

            // Add API error details if available
            if ($api_data && isset($api_data['error'])) {
                $error_details['api_error'] = $api_data['error'];
            }

            // Add specific troubleshooting based on HTTP status
            switch ($http_code) {
                case 400:
                    $error_details['issue'] = 'Bad Request - Invalid request format';
                    $error_details['solutions'] = [
                        'Check if your Key ID is correct',
                        'Verify the API Key format (UUID)',
                        'Ensure request body is valid JSON',
                        'Check API documentation for required fields'
                    ];
                    break;
                case 401:
                case 403:
                    $error_details['issue'] = 'Unauthorized - Invalid credentials';
                    $error_details['solutions'] = [
                        'Verify your Key ID is correct',
                        'Check if your API Key is correct',
                        'Ensure credentials are for the correct environment (Test/Production)',
                        'Verify your Ratehawk account is active',
                        'Contact Ratehawk support for credential verification'
                    ];
                    break;
                case 429:
                    $error_details['issue'] = 'Rate Limit Exceeded';
                    $error_details['solutions'] = [
                        'You have exceeded the API rate limit',
                        'Wait a few minutes before trying again',
                        'Check your API usage limits',
                        'Contact Ratehawk to increase your rate limits'
                    ];
                    break;
                case 500:
                case 502:
                case 503:
                    $error_details['issue'] = 'Server Error - Ratehawk API issue';
                    $error_details['solutions'] = [
                        'This is a temporary server error',
                        'Wait a few minutes and try again',
                        'Check Ratehawk API status',
                        'Try again later if issue persists'
                    ];
                    break;
                default:
                    $error_details['issue'] = 'Unexpected HTTP response';
                    $error_details['solutions'] = [
                        'Check your network connectivity',
                        'Verify Key ID and API Key are correct',
                        'Ensure you are using the correct environment',
                        'Try again in a few minutes',
                        'Contact Ratehawk support'
                    ];
            }

            $response['data'] = $error_details;
            $response['message'] = "Authentication failed - HTTP {$http_code}";
            throw new Exception("HTTP {$http_code} error from Ratehawk API");
        }

    } catch (Exception $e) {
        $response['debug']['validation_steps'][] = '[ERROR] ' . $e->getMessage();

        if (!isset($response['data']) || $response['data'] === null) {
            $response['data'] = [
                'error_type' => 'validation_error',
                'error_code' => 'credential_validation_failed',
                'error_description' => $e->getMessage(),
                'quick_fixes' => [
                    'Ensure you have valid Ratehawk credentials',
                    'Check Key ID is a numeric value',
                    'Check API Key is in UUID format',
                    'Verify credentials match your environment (Test/Production)',
                    'Verify network connectivity to Ratehawk servers',
                    'Get credentials from Ratehawk Partner Portal',
                    'Contact Ratehawk support if issues persist'
                ]
            ];
        }

        if (empty($response['message'])) {
            $response['message'] = 'Ratehawk API credential validation failed';
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

});
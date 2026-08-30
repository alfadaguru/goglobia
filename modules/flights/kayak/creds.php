<?php

global $router;

$router->post('flights/kayak/creds', function() {

// Start timing for performance metrics
$start_time = microtime(true);

// Initialize standardized response array
$response = [
    'success' => false,
    'message' => '',
    'data' => null,
    'metadata' => [
        'module' => 'kayak',
        'service' => 'flights',
        'provider' => 'Kayak Affiliates',
        'api_version' => 'v1',
        'timestamp' => date('c'),
        'response_time_ms' => 0,
        'environment' => 'sandbox'
    ],
    'debug' => [
        'endpoint_used' => '',
        'request_method' => 'GET',
        'validation_steps' => [],
        'api_response' => null,
        'raw_response' => null
    ]
];

try {
    $response['debug']['validation_steps'][] = 'Starting Kayak Affiliates API credential validation process';

    // Validate required parameters
    if (!isset($_POST['c1']) || empty(trim($_POST['c1']))) {
        $response['message'] = 'API Key (c1) is required for Kayak Affiliates API';
        $response['debug']['validation_steps'][] = 'Validation failed: Missing API Key';
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $response['debug']['validation_steps'][] = 'Required credentials provided';

    // Extract credentials
    $api_key = trim($_POST['c1']);

    // Check for environment mode (optional, defaults to sandbox)
    $env = isset($_POST['env']) ? $_POST['env'] : 'sandbox';
    $response['metadata']['environment'] = $env;

    // Set appropriate endpoint based on environment
    if ($env === 'production' || $env === 'live') {
        $api_endpoint = 'https://en-us.kayakaffiliates.com';
        $response['debug']['validation_steps'][] = 'Environment: Production endpoint selected';
    } else {
        $api_endpoint = 'https://sandbox-en-us.kayakaffiliates.com';
        $response['debug']['validation_steps'][] = 'Environment: Sandbox endpoint selected';
    }

    $response['debug']['validation_steps'][] = 'Initiating test request to Kayak Affiliates API';

    // Generate a test userTrackId (required for API requests)
    $userTrackId = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    // Test with autocomplete API (simple endpoint with minimal requirements)
    $test_url = $api_endpoint . '/api/affiliate/autocomplete/v1/flights?apiKey=' . urlencode($api_key) . '&searchTerm=' . urlencode('New York');
    $response['debug']['endpoint_used'] = $test_url;

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $test_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: kayakaffiliateapp',
            'x-original-client-ip: 8.8.8.8'
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => false
    ]);

    $result = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($curl);
    $curl_info = curl_getinfo($curl);

    // Store raw response for debugging
    $response['debug']['raw_response'] = $result;
    $response['debug']['validation_steps'][] = "API request completed with HTTP code: {$http_code}";

    // Check for cURL errors
    if ($curl_error) {
        $response['message'] = 'Network connection error occurred';
        $response['data'] = [
            'error_type' => 'network_error',
            'error_code' => 'CURL_ERROR',
            'error_description' => $curl_error,
            'connection_details' => [
                'endpoint' => $test_url,
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
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Parse the JSON response
    $api_data = json_decode($result, true);
    $response['debug']['api_response'] = $api_data;

    if ($http_code === 200 && $api_data && !isset($api_data['error'])) {
        // Success - API key is valid
        $response['success'] = true;
        $response['message'] = 'Kayak Affiliates API credentials validated successfully';
        $response['data'] = [
            'connection_status' => 'connected',
            'authentication' => [
                'status' => 'authenticated',
                'key_type' => 'API Key',
                'key_prefix' => substr($api_key, 0, 8) . '...',
                'api_version' => 'Affiliate API v1'
            ],
            'api_details' => [
                'provider' => 'Kayak Affiliates',
                'environment' => $env,
                'endpoint' => $api_endpoint,
                'supported_services' => [
                    'Flight Search API',
                    'Hotel Search API',
                    'Car Search API',
                    'Autocomplete API',
                    'Price Insights API',
                    'CompareTo Ads API',
                    'Inline Ads API',
                    'Static Data Feed API'
                ]
            ],
            'account_info' => [
                'api_key' => substr($api_key, 0, 8) . '...' . substr($api_key, -4),
                'validated_at' => date('Y-m-d H:i:s'),
                'validation_method' => 'Autocomplete API Test',
                'test_endpoint' => 'autocomplete/v1/flights',
                'test_query' => 'New York'
            ],
            'rate_limits' => [
                'autocomplete' => $env === 'sandbox' ? '100 / hour' : 'Per agreement',
                'flight_search' => $env === 'sandbox' ? '250 / hour' : 'Per agreement',
                'hotel_search' => $env === 'sandbox' ? '250 / hour' : 'Per agreement',
                'car_search' => $env === 'sandbox' ? '250 / hour' : 'Per agreement',
                'note' => 'Sandbox limits shown. Production limits per partnership agreement.',
                'documentation' => 'https://kayakaffiliates.com/docs'
            ],
            'test_data' => [
                'endpoint_tested' => 'autocomplete/v1/flights',
                'response_valid' => true,
                'results_count' => is_array($api_data) ? count($api_data) : 0,
                'sample_result' => isset($api_data[0]) ? [
                    'name' => $api_data[0]['name'] ?? 'N/A',
                    'code' => $api_data[0]['code'] ?? 'N/A',
                    'type' => $api_data[0]['type'] ?? 'N/A'
                ] : null
            ]
        ];
        $response['debug']['validation_steps'][] = 'Authentication successful - Valid API response received';

    } elseif ($http_code === 401) {
        // Unauthorized - Invalid API key
        $response['message'] = 'Authentication failed - Invalid API key';
        $response['data'] = [
            'error_type' => 'authentication_error',
            'error_code' => $api_data['error']['code'] ?? 'UNAUTHORIZED',
            'error_description' => $api_data['error']['message'] ?? 'The API key is invalid or missing',
            'error_detail' => $api_data['error']['detail'] ?? 'Please verify your API key',
            'api_response' => $api_data,
            'provider_details' => [
                'provider' => 'Kayak Affiliates',
                'environment' => $env,
                'endpoint' => $api_endpoint,
                'documentation' => 'https://kayakaffiliates.com/docs'
            ],
            'troubleshooting' => [
                'Verify your API key is correct',
                'Check if you copied the complete API key',
                'Ensure no extra spaces or characters',
                'Confirm your Kayak Affiliate account is active',
                'Check if the API key has been revoked',
                'Verify you are using the correct environment (sandbox vs production)'
            ],
            'support' => [
                'kayak_support' => 'https://kayakaffiliates.com/contact',
                'documentation' => 'https://kayakaffiliates.com/docs',
                'signup' => 'https://kayakaffiliates.com/signup'
            ]
        ];
        $response['debug']['validation_steps'][] = 'Authentication failed - Invalid API key';

    } elseif ($http_code === 403) {
        // Forbidden - Insufficient permissions or rate limited
        $response['message'] = 'Access forbidden - Insufficient permissions or rate limit exceeded';
        $response['data'] = [
            'error_type' => 'permission_error',
            'error_code' => $api_data['error']['code'] ?? 'FORBIDDEN',
            'error_description' => $api_data['error']['message'] ?? 'Access to this resource is forbidden',
            'error_detail' => $api_data['error']['detail'] ?? 'Your account may not have access or rate limit exceeded',
            'api_response' => $api_data,
            'troubleshooting' => [
                'Check if you exceeded the rate limit',
                'Verify your account permissions',
                'Ensure your affiliate agreement is active',
                'Check if your API key has the required scopes',
                'Wait before retrying if rate limited',
                'Contact Kayak support to review your account'
            ]
        ];
        $response['debug']['validation_steps'][] = 'Access forbidden - Permission denied or rate limited';

    } elseif ($http_code === 400) {
        // Bad request - Malformed request
        $response['message'] = 'Bad request - Invalid request format or parameters';
        $response['data'] = [
            'error_type' => 'request_error',
            'error_code' => $api_data['error']['code'] ?? 'BAD_REQUEST',
            'error_description' => $api_data['error']['message'] ?? 'The request is malformed',
            'error_detail' => $api_data['error']['detail'] ?? 'Please check the request format',
            'api_response' => $api_data,
            'request_details' => [
                'method' => 'GET',
                'endpoint' => $test_url,
                'required_headers' => [
                    'User-Agent' => 'kayakaffiliateapp',
                    'x-original-client-ip' => '8.8.8.8'
                ]
            ],
            'troubleshooting' => [
                'Check request format',
                'Verify API key is in query parameter',
                'Ensure required headers are present',
                'Review parameter requirements',
                'Check API documentation for changes'
            ]
        ];
        $response['debug']['validation_steps'][] = 'Bad request - Invalid format';

    } elseif ($http_code === 404) {
        // Not found - Endpoint doesn't exist
        $response['message'] = 'API endpoint not found';
        $response['data'] = [
            'error_type' => 'endpoint_error',
            'error_code' => 'NOT_FOUND',
            'error_description' => 'The API endpoint could not be found',
            'http_details' => [
                'status_code' => $http_code,
                'endpoint_tested' => $test_url
            ],
            'api_response' => $api_data,
            'troubleshooting' => [
                'Verify the endpoint URL is correct',
                'Check if you are using the correct environment',
                'Ensure you have access to this API endpoint',
                'Review API documentation for endpoint changes',
                'Contact Kayak support if issue persists'
            ]
        ];
        $response['debug']['validation_steps'][] = 'Endpoint not found - HTTP 404';

    } elseif ($http_code === 429) {
        // Rate limit exceeded
        $response['message'] = 'Rate limit exceeded - Too many requests';
        $response['data'] = [
            'error_type' => 'rate_limit_error',
            'error_code' => 'RATE_LIMIT_EXCEEDED',
            'error_description' => 'API rate limit has been exceeded',
            'api_response' => $api_data,
            'rate_limit_info' => [
                'provider' => 'Kayak Affiliates',
                'sandbox_limits' => [
                    'autocomplete' => '100 / hour',
                    'flight_search' => '250 / hour',
                    'hotel_search' => '250 / hour',
                    'car_search' => '250 / hour'
                ],
                'retry_after' => $curl_info['retry_after'] ?? 'Unknown',
                'documentation' => 'https://kayakaffiliates.com/docs/rate-limiting'
            ],
            'troubleshooting' => [
                'Wait before making another request',
                'Implement proper rate limiting in your application',
                'Use request queuing to manage API calls',
                'Monitor your usage against limits',
                'Contact Kayak to discuss higher rate limits for production'
            ]
        ];
        $response['debug']['validation_steps'][] = 'Rate limit exceeded';

    } elseif ($http_code >= 500) {
        // Server error
        $response['message'] = 'Kayak Affiliates API server error - Service temporarily unavailable';
        $response['data'] = [
            'error_type' => 'server_error',
            'error_code' => 'HTTP_' . $http_code,
            'error_description' => 'Kayak server returned an error',
            'http_details' => [
                'status_code' => $http_code,
                'status_text' => getHttpStatusText($http_code)
            ],
            'api_response' => $api_data,
            'provider_status' => [
                'provider' => 'Kayak Affiliates',
                'support' => 'https://kayakaffiliates.com/contact'
            ],
            'troubleshooting' => [
                'Retry request after some time',
                'Contact Kayak support if issue persists',
                'Check if this is a maintenance window',
                'Verify your account status'
            ]
        ];
        $response['debug']['validation_steps'][] = "Server error received - HTTP {$http_code}";

    } elseif ($api_data && isset($api_data['error'])) {
        // API returned an error in the response
        $response['message'] = 'Kayak API returned an error';
        $response['data'] = [
            'error_type' => 'api_error',
            'error_code' => $api_data['error']['code'] ?? 'UNKNOWN',
            'error_description' => $api_data['error']['message'] ?? 'An error occurred',
            'error_detail' => $api_data['error']['detail'] ?? 'No additional details',
            'http_code' => $http_code,
            'api_response' => $api_data,
            'troubleshooting' => [
                'Review the error message and code',
                'Check API documentation for this error',
                'Verify your API key and parameters',
                'Contact Kayak support with error details'
            ]
        ];
        $error_code = $api_data['error']['code'] ?? 'UNKNOWN';
        $response['debug']['validation_steps'][] = "API error - {$error_code}";

    } else {
        // Other unexpected responses
        $response['message'] = "Unexpected API response - HTTP {$http_code}";
        $response['data'] = [
            'error_type' => 'unexpected_error',
            'error_code' => 'HTTP_' . $http_code,
            'error_description' => 'Received unexpected response from API',
            'http_details' => [
                'status_code' => $http_code,
                'content_type' => $curl_info['content_type'] ?? 'unknown'
            ],
            'api_response' => $api_data,
            'troubleshooting' => [
                'Check if API is functioning normally',
                'Verify request parameters',
                'Review API documentation',
                'Contact Kayak support with these details'
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

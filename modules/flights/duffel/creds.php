<?php

global $router;

$router->post('flights/duffel/creds', function() {

// Start timing for performance metrics
$start_time = microtime(true);

// Initialize standardized response array
$response = [
    'success' => false,
    'message' => '',
    'data' => null,
    'metadata' => [
        'module' => 'duffel',
        'service' => 'flights',
        'provider' => 'Duffel',
        'api_version' => 'v2',
        'timestamp' => date('c'),
        'response_time_ms' => 0,
        'environment' => 'test'
    ],
    'debug' => [
        'endpoint_used' => '',
        'request_method' => 'POST',
        'validation_steps' => [],
        'api_response' => null,
        'raw_response' => null
    ]
];

try {
    $response['debug']['validation_steps'][] = 'Starting Duffel API credential validation process';

    // Validate required parameters
    if (!isset($_POST['c1']) || empty(trim($_POST['c1']))) {
        $response['message'] = 'API Token (c1) is required for Duffel API';
        $response['debug']['validation_steps'][] = 'Validation failed: Missing API Token';
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $response['debug']['validation_steps'][] = 'Required credentials provided';

    // Check for environment mode (optional, defaults to test)
    $env = isset($_POST['env']) ? $_POST['env'] : 'test';
    $response['metadata']['environment'] = $env;

    // Set appropriate endpoint based on environment
    if ($env === 'production' || $env === 'live') {
        $api_endpoint = 'https://api.duffel.com/';
        $response['debug']['validation_steps'][] = 'Environment: Production endpoints selected';
    } else {
        $api_endpoint = 'https://api.duffel.com/';  // Duffel uses same endpoint for both
        $response['debug']['validation_steps'][] = 'Environment: Using production endpoint (Duffel standard)';
    }

    $response['debug']['endpoint_used'] = $api_endpoint . 'air/aircraft';

    // Extract credentials
    $api_token = trim($_POST['c1']);

    $response['debug']['validation_steps'][] = 'Initiating test request to Duffel API';

    // Test API connection with a simple endpoint (aircraft list)
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $api_endpoint . 'air/aircraft?limit=1',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $api_token,
            'Duffel-Version: v2'
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Duffel-Test/1.0'
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
                'endpoint' => $api_endpoint . 'air/aircraft',
                'timeout' => 30,
                'connect_timeout' => 10,
                'ssl_verify' => true
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

    // Parse the response
    $api_data = json_decode($result, true);
    $response['debug']['api_response'] = $api_data;

    if ($http_code === 200 && isset($api_data['data'])) {
        // Success - credentials are valid
        $response['success'] = true;
        $response['message'] = 'Duffel API credentials validated successfully';
        $response['data'] = [
            'connection_status' => 'connected',
            'authentication' => [
                'status' => 'authenticated',
                'token_type' => 'Bearer',
                'token_prefix' => substr($api_token, 0, 8) . '...',
                'api_version' => 'v2'
            ],
            'api_details' => [
                'provider' => 'Duffel',
                'environment' => $env,
                'endpoint' => $api_endpoint,
                'supported_services' => [
                    'Flight Search',
                    'Flight Booking',
                    'Flight Cancellation',
                    'Flight Changes',
                    'Ancillary Services',
                    'Seat Selection',
                    'Baggage Management',
                    'Payment Processing',
                    'Refund Processing',
                    'Loyalty Programs'
                ]
            ],
            'account_info' => [
                'api_token' => substr($api_token, 0, 8) . '...',
                'validated_at' => date('Y-m-d H:i:s'),
                'validation_method' => 'Bearer Token Authentication',
                'test_endpoint' => 'aircraft'
            ],
            'rate_limits' => [
                'note' => 'Rate limits depend on your Duffel subscription',
                'documentation' => 'https://duffel.com/docs/api/overview/rate-limiting'
            ],
            'test_data' => [
                'endpoint_tested' => 'air/aircraft',
                'response_count' => count($api_data['data'] ?? []),
                'sample_aircraft' => isset($api_data['data'][0]) ? $api_data['data'][0]['name'] ?? 'Unknown' : 'None'
            ]
        ];
        $response['debug']['validation_steps'][] = 'Authentication successful - Valid API response received';

    } elseif ($http_code === 401) {
        // Invalid credentials
        $response['message'] = 'Authentication failed - Invalid API token provided';
        $response['data'] = [
            'error_type' => 'authentication_error',
            'error_code' => $api_data['errors'][0]['code'] ?? 'invalid_token',
            'error_description' => $api_data['errors'][0]['title'] ?? 'The API token is invalid or expired',
            'error_detail' => $api_data['errors'][0]['detail'] ?? 'Please check your API token',
            'api_response' => $api_data,
            'provider_details' => [
                'provider' => 'Duffel',
                'environment' => $env,
                'endpoint' => $api_endpoint,
                'documentation' => 'https://duffel.com/docs/api/authentication'
            ],
            'troubleshooting' => [
                'Verify your API token is correct',
                'Check if the token has expired',
                'Ensure you are using the correct environment token',
                'Confirm your Duffel account is active',
                'Check if your token has the required permissions'
            ],
            'support' => [
                'duffel_support' => 'https://duffel.com/support',
                'documentation' => 'https://duffel.com/docs',
                'dashboard' => 'https://app.duffel.com/'
            ]
        ];
        $response['debug']['validation_steps'][] = 'Authentication failed - Invalid token';

    } elseif ($http_code === 403) {
        // Forbidden - insufficient permissions
        $response['message'] = 'Access forbidden - Insufficient permissions or account limitations';
        $response['data'] = [
            'error_type' => 'permission_error',
            'error_code' => $api_data['errors'][0]['code'] ?? 'forbidden',
            'error_description' => $api_data['errors'][0]['title'] ?? 'Access to this resource is forbidden',
            'error_detail' => $api_data['errors'][0]['detail'] ?? 'Your account may not have access to this endpoint',
            'api_response' => $api_data,
            'troubleshooting' => [
                'Check your account permissions',
                'Verify your subscription plan includes this feature',
                'Contact Duffel support to upgrade your account',
                'Ensure your API token has the required scopes'
            ]
        ];
        $response['debug']['validation_steps'][] = 'Access forbidden - Permission denied';

    } elseif ($http_code === 400) {
        // Bad request
        $response['message'] = 'Bad request - Invalid request format or parameters';
        $response['data'] = [
            'error_type' => 'request_error',
            'error_code' => $api_data['errors'][0]['code'] ?? 'bad_request',
            'error_description' => $api_data['errors'][0]['title'] ?? 'The request is malformed',
            'error_detail' => $api_data['errors'][0]['detail'] ?? 'Please check the request format',
            'api_response' => $api_data,
            'request_details' => [
                'method' => 'GET',
                'endpoint' => $api_endpoint . 'air/aircraft',
                'headers' => [
                    'Authorization' => 'Bearer [hidden]',
                    'Duffel-Version' => 'v2'
                ]
            ],
            'troubleshooting' => [
                'Check request format',
                'Verify API version header (currently using v2)',
                'Ensure proper content-type',
                'Review parameter formatting',
                'If version error: API version has been updated, contact support'
            ]
        ];
        $response['debug']['validation_steps'][] = 'Request validation failed - Bad request format';

    } elseif ($http_code >= 500) {
        // Server error
        $response['message'] = 'Duffel API server error - Service temporarily unavailable';
        $response['data'] = [
            'error_type' => 'server_error',
            'error_code' => 'HTTP_' . $http_code,
            'error_description' => 'Duffel API server returned an error',
            'http_details' => [
                'status_code' => $http_code,
                'status_text' => getHttpStatusText($http_code),
                'response_body' => $result
            ],
            'api_response' => $api_data,
            'provider_status' => [
                'provider' => 'Duffel',
                'status_page' => 'https://status.duffel.com/',
                'support' => 'https://duffel.com/support'
            ],
            'troubleshooting' => [
                'Check Duffel API status page',
                'Retry request after some time',
                'Contact Duffel support if issue persists',
                'Verify your account status'
            ]
        ];
        $response['debug']['validation_steps'][] = "Server error received - HTTP {$http_code}";

    } elseif ($http_code === 429) {
        // Rate limit exceeded
        $response['message'] = 'Rate limit exceeded - Too many requests';
        $response['data'] = [
            'error_type' => 'rate_limit_error',
            'error_code' => 'RATE_LIMIT_EXCEEDED',
            'error_description' => 'API rate limit has been exceeded',
            'api_response' => $api_data,
            'rate_limit_info' => [
                'provider' => 'Duffel',
                'documentation' => 'https://duffel.com/docs/api/overview/rate-limiting',
                'retry_after' => $curl_info['retry_after'] ?? 'Unknown'
            ],
            'troubleshooting' => [
                'Wait before making another request',
                'Implement proper rate limiting in your application',
                'Consider upgrading your Duffel plan',
                'Use request queuing to manage API calls'
            ]
        ];
        $response['debug']['validation_steps'][] = 'Rate limit exceeded';

    } else {
        // Other errors
        $response['message'] = "Unexpected API response - HTTP {$http_code}";
        $response['data'] = [
            'error_type' => 'unexpected_error',
            'error_code' => 'HTTP_' . $http_code,
            'error_description' => 'Received unexpected response from API',
            'http_details' => [
                'status_code' => $http_code,
                'response_headers' => $curl_info,
                'response_body' => $result
            ],
            'api_response' => $api_data,
            'troubleshooting' => [
                'Check API documentation for this status code',
                'Verify request parameters',
                'Contact support with this error details',
                'Check if API endpoint has changed'
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
            'trace' => '[redacted]'
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